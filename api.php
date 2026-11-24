<?php
// CCDI Student Evaluation Portal - JSON API
declare(strict_types=1);
require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function ok(array $data = []): never
{
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(string $msg, int $code = 400): never
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function current_user(): ?array
{
    if (empty($_SESSION['uid'])) {
        return null;
    }
    $st = db()->prepare('SELECT id, role, full_name, email, sid, course, block FROM users WHERE id = ?');
    $st->execute([$_SESSION['uid']]);
    return $st->fetch() ?: null;
}

function require_role(string $role): array
{
    $u = current_user();
    if (!$u) {
        fail('Please sign in first.', 401);
    }
    if ($u['role'] !== $role) {
        fail('You do not have permission to do that.', 403);
    }
    return $u;
}

function active_term(): string
{
    $r = db()->query("SELECT v FROM settings WHERE k = 'active_term'")->fetch();
    return $r ? $r['v'] : '';
}

function str(array $in, string $key, int $max = 255): string
{
    $v = $in[$key] ?? '';
    return is_string($v) ? mb_substr(trim($v), 0, $max) : '';
}

function user_payload(?array $u): ?array
{
    if (!$u) {
        return null;
    }
    return [
        'role'       => $u['role'],
        'name'       => $u['full_name'],
        'email'      => $u['email'],
        'sid'        => $u['sid'],
        'course'     => $u['course'],
        'block'      => $u['block'],
        'section_ok' => !empty($_SESSION['section_ok']),
    ];
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$in = [];

if ($method === 'POST') {
    $in = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($in)) {
        $in = [];
    }
    $tok = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf'], $tok)) {
        fail('Your session expired. Please refresh the page and try again.', 419);
    }
}

try {
    switch ($action) {

        // ---------- public ----------
        case 'config':
            ok([
                'csrf'    => $_SESSION['csrf'],
                'courses' => COURSES,
                'blocks'  => BLOCKS,
                'sid_example' => SID_EXAMPLE,
                'user'    => user_payload(current_user()),
            ]);

        case 'register': {
            $name  = str($in, 'name', 100);
            $email = strtolower(str($in, 'email', 150));
            $sid   = strtoupper(str($in, 'sid', 20));
            $pass  = is_string($in['password'] ?? null) ? $in['password'] : '';

            if (mb_strlen($name) < 2) fail('Please enter your full name.');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Please enter a valid email address.');
            if (!valid_sid($sid)) fail('Invalid Student ID. Use the format ' . SID_EXAMPLE . '.');
            if (strlen($pass) < 8 || !preg_match('/[A-Za-z]/', $pass) || !preg_match('/\d/', $pass)) {
                fail('Password must be at least 8 characters and include a letter and a number.');
            }

            $pdo = db();
            $st = $pdo->prepare('SELECT email, sid FROM users WHERE email = ? OR sid = ?');
            $st->execute([$email, $sid]);
            foreach ($st->fetchAll() as $row) {
                if ($row['email'] === $email) fail('An account with that email already exists.', 409);
                if ($row['sid'] === $sid) fail('That Student ID is already registered.', 409);
            }

            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $pdo->prepare("INSERT INTO users (role, full_name, email, sid, password_hash) VALUES ('student', ?, ?, ?, ?)")
                ->execute([$name, $email, $sid, $hash]);
            ok(['message' => 'Account created. You can now sign in with your new password.', 'email' => $email]);
        }

        case 'login': {
            $mode  = ($in['mode'] ?? 'student') === 'admin' ? 'admin' : 'student';
            $ident = strtolower(str($in, 'identifier', 150));
            $pass  = is_string($in['password'] ?? null) ? $in['password'] : '';

            // Basic brute-force throttle (per session)
            $now = time();
            $_SESSION['fails'] = array_values(array_filter($_SESSION['fails'] ?? [], fn($t) => $t > $now - 300));
            if (count($_SESSION['fails']) >= 8) {
                fail('Too many failed attempts. Please wait a few minutes and try again.', 429);
            }

            if ($ident === '' || $pass === '') fail('Enter your credentials.');

            if ($mode === 'admin') {
                // Fixed demonstration administrator credentials, checked on the server.
                if (!hash_equals('admin', $ident) || !hash_equals('admin123', $pass)) {
                    $_SESSION['fails'][] = $now;
                    fail('Incorrect administrator username or password.', 401);
                }
                $st = db()->query("SELECT * FROM users WHERE role = 'admin' ORDER BY id LIMIT 1");
                $u = $st->fetch();
                if (!$u) {
                    // Keep a real DB user so existing admin endpoints and sessions work.
                    $email = 'admin@ccdi.local';
                    $st = db()->prepare("INSERT INTO users (role, full_name, email, password_hash) VALUES ('admin', 'Administrator', ?, ?)");
                    $st->execute([$email, password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT)]);
                    $id = (int)db()->lastInsertId();
                    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
                    $st->execute([$id]);
                    $u = $st->fetch();
                }
            } else {
                // Demo student access: any nonempty identifier/password is accepted.
                // Reuse the student's record so course selection/evaluations persist.
                $st = db()->prepare("SELECT * FROM users WHERE role = 'student' AND (email = ? OR sid = ?) LIMIT 1");
                $st->execute([$ident, strtoupper($ident)]);
                $u = $st->fetch();
                if (!$u) {
                    $email = filter_var($ident, FILTER_VALIDATE_EMAIL)
                        ? $ident : 'student-' . hash('sha256', $ident) . '@ccdi.local';
                    // Avoid colliding with an administrator's email.
                    $st = db()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
                    $st->execute([$email]);
                    if ($st->fetch()) fail('This identifier is unavailable for student sign-in.', 409);
                    $name = filter_var($ident, FILTER_VALIDATE_EMAIL) ? explode('@', $ident)[0] : $ident;
                    $st = db()->prepare("INSERT INTO users (role, full_name, email, password_hash) VALUES ('student', ?, ?, ?)");
                    $st->execute([mb_substr($name, 0, 100), $email, password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT)]);
                    $id = (int)db()->lastInsertId();
                    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
                    $st->execute([$id]);
                    $u = $st->fetch();
                }
            }

            session_regenerate_id(true);
            $_SESSION['uid'] = (int)$u['id'];
            $_SESSION['section_ok'] = ($u['role'] === 'admin');
            $_SESSION['fails'] = [];
            ok(['user' => user_payload(current_user())]);
        }

        case 'logout':
            $_SESSION = [];
            session_regenerate_id(true);
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            ok(['csrf' => $_SESSION['csrf']]);

        // ---------- student ----------
        case 'select_section': {
            $u = require_role('student');
            $course = strtoupper(str($in, 'course', 10));
            $block  = strtoupper(str($in, 'block', 10));
            if (!in_array($course, COURSES, true)) fail('Please choose a valid course.');
            if (!in_array($block, BLOCKS, true)) fail('Please choose a valid block.');
            db()->prepare('UPDATE users SET course = ?, block = ? WHERE id = ?')->execute([$course, $block, $u['id']]);
            $_SESSION['section_ok'] = true;
            ok(['user' => user_payload(current_user())]);
        }

        case 'my_professors': {
            $u = require_role('student');
            if (empty($_SESSION['section_ok']) || !$u['course'] || !$u['block']) fail('Select your course and block first.', 403);
            $term = active_term();
            $st = db()->prepare(
                'SELECT a.id, a.subject, p.full_name AS professor, (e.id IS NOT NULL) AS done
                 FROM allocations a
                 JOIN professors p ON p.id = a.professor_id
                 LEFT JOIN evaluations e ON e.allocation_id = a.id AND e.student_id = ?
                 WHERE a.course = ? AND a.block = ? AND a.term = ?
                 ORDER BY p.full_name, a.subject'
            );
            $st->execute([$u['id'], $u['course'], $u['block'], $term]);
            $rows = array_map(fn($r) => [
                'id' => (int)$r['id'], 'subject' => $r['subject'],
                'professor' => $r['professor'], 'done' => (bool)$r['done'],
            ], $st->fetchAll());
            ok(['term' => $term, 'professors' => $rows]);
        }

        case 'submit_evaluation': {
            $u = require_role('student');
            if (empty($_SESSION['section_ok'])) fail('Select your course and block first.', 403);
            $aid = (int)($in['allocation_id'] ?? 0);
            $term = active_term();

            $st = db()->prepare('SELECT id FROM allocations WHERE id = ? AND course = ? AND block = ? AND term = ?');
            $st->execute([$aid, $u['course'], $u['block'], $term]);
            if (!$st->fetch()) fail('That professor is not assigned to your section.', 403);

            $scores = [];
            foreach (['teaching', 'mastery', 'punctuality'] as $k) {
                $v = $in[$k] ?? null;
                if (!is_int($v) || $v < 1 || $v > 5) fail('Please rate every criterion from 1 to 5.');
                $scores[$k] = $v;
            }
            $comment = str($in, 'comment', 1000);

            try {
                db()->prepare('INSERT INTO evaluations (student_id, allocation_id, teaching, mastery, punctuality, comment) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$u['id'], $aid, $scores['teaching'], $scores['mastery'], $scores['punctuality'], $comment !== '' ? $comment : null]);
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') fail('You already evaluated this professor.', 409);
                throw $e;
            }

            // All evaluations done? -> simulated confirmation email (once per term)
            $st = db()->prepare(
                'SELECT COUNT(*) FROM allocations a
                 LEFT JOIN evaluations e ON e.allocation_id = a.id AND e.student_id = ?
                 WHERE a.course = ? AND a.block = ? AND a.term = ? AND e.id IS NULL'
            );
            $st->execute([$u['id'], $u['course'], $u['block'], $term]);
            $pending = (int)$st->fetchColumn();

            $email = null;
            if ($pending === 0) {
                $chk = db()->prepare('SELECT COUNT(*) FROM email_log WHERE student_id = ? AND term = ?');
                $chk->execute([$u['id'], $term]);
                if ((int)$chk->fetchColumn() === 0) {
                    $subject = 'CCDI Teacher Evaluation - Completed';
                    $body = 'You have successfully completed your professor evaluations for this semester. Thank you!';
                    db()->prepare('INSERT INTO email_log (student_id, to_email, subject, body, term) VALUES (?, ?, ?, ?, ?)')
                        ->execute([$u['id'], $u['email'], $subject, $body, $term]);
                    $email = ['to' => $u['email'], 'subject' => $subject, 'body' => $body];
                }
            }
            ok(['pending' => $pending, 'email' => $email]);
        }

        // ---------- admin ----------
        case 'admin_overview': {
            require_role('admin');
            $pdo = db();
            $profs = $pdo->query(
                'SELECT p.id, p.full_name, COUNT(a.id) AS assignments
                 FROM professors p LEFT JOIN allocations a ON a.professor_id = p.id
                 GROUP BY p.id ORDER BY p.full_name'
            )->fetchAll();
            $allocs = $pdo->query(
                'SELECT a.id, a.professor_id, p.full_name AS professor, a.course, a.block, a.subject, a.term
                 FROM allocations a JOIN professors p ON p.id = a.professor_id
                 ORDER BY a.course, a.block, p.full_name'
            )->fetchAll();
            ok(['term' => active_term(), 'professors' => $profs, 'allocations' => $allocs]);
        }

        case 'save_professor': {
            require_role('admin');
            $id = (int)($in['id'] ?? 0);
            $name = str($in, 'name', 100);
            if (mb_strlen($name) < 2) fail('Enter the professor\'s name.');
            if ($id > 0) {
                db()->prepare('UPDATE professors SET full_name = ? WHERE id = ?')->execute([$name, $id]);
            } else {
                db()->prepare('INSERT INTO professors (full_name) VALUES (?)')->execute([$name]);
            }
            ok();
        }

        case 'delete_professor': {
            require_role('admin');
            db()->prepare('DELETE FROM professors WHERE id = ?')->execute([(int)($in['id'] ?? 0)]);
            ok();
        }

        case 'save_allocation': {
            require_role('admin');
            $pid = (int)($in['professor_id'] ?? 0);
            $course = strtoupper(str($in, 'course', 10));
            $block = strtoupper(str($in, 'block', 10));
            $subject = str($in, 'subject', 120);
            if (!in_array($course, COURSES, true) || !in_array($block, BLOCKS, true)) fail('Choose a valid course and block.');
            if ($subject === '') fail('Enter the subject.');
            $st = db()->prepare('SELECT id FROM professors WHERE id = ?');
            $st->execute([$pid]);
            if (!$st->fetch()) fail('Choose a professor.');
            try {
                db()->prepare('INSERT INTO allocations (professor_id, course, block, subject, term) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$pid, $course, $block, $subject, active_term()]);
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') fail('That assignment already exists.', 409);
                throw $e;
            }
            ok();
        }

        case 'delete_allocation': {
            require_role('admin');
            db()->prepare('DELETE FROM allocations WHERE id = ?')->execute([(int)($in['id'] ?? 0)]);
            ok();
        }

        case 'set_term': {
            require_role('admin');
            $term = str($in, 'term', 60);
            if ($term === '') fail('Enter a term name.');
            db()->prepare("INSERT INTO settings (k, v) VALUES ('active_term', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([$term]);
            ok(['term' => $term]);
        }

        case 'evaluations': {
            require_role('admin');
            $course = strtoupper((string)($_GET['course'] ?? ''));
            $block  = strtoupper((string)($_GET['block'] ?? ''));
            if (!in_array($course, COURSES, true)) fail('Choose a course.');
            $sql = 'SELECT a.id, a.block, a.subject, a.term, p.full_name AS professor,
                           COUNT(e.id) AS responses,
                           AVG(e.teaching) AS teaching, AVG(e.mastery) AS mastery, AVG(e.punctuality) AS punctuality
                    FROM allocations a
                    JOIN professors p ON p.id = a.professor_id
                    LEFT JOIN evaluations e ON e.allocation_id = a.id
                    WHERE a.course = ? AND a.term = ?';
            $args = [$course, active_term()];
            if (in_array($block, BLOCKS, true)) {
                $sql .= ' AND a.block = ?';
                $args[] = $block;
            }
            $sql .= ' GROUP BY a.id ORDER BY a.block, p.full_name';
            $st = db()->prepare($sql);
            $st->execute($args);
            $groups = $st->fetchAll();

            $cst = db()->prepare(
                'SELECT e.teaching, e.mastery, e.punctuality, e.comment, e.created_at, u.full_name, u.sid
                 FROM evaluations e JOIN users u ON u.id = e.student_id
                 WHERE e.allocation_id = ? ORDER BY e.created_at DESC'
            );
            foreach ($groups as &$g) {
                foreach (['teaching', 'mastery', 'punctuality'] as $k) {
                    $g[$k] = $g[$k] === null ? null : round((float)$g[$k], 2);
                }
                $g['responses'] = (int)$g['responses'];
                $cst->execute([$g['id']]);
                $g['entries'] = $cst->fetchAll();
            }
            unset($g);
            ok(['term' => active_term(), 'groups' => $groups]);
        }

        default:
            fail('Unknown action.', 404);
    }
} catch (PDOException $e) {
    error_log('CCDI portal DB error: ' . $e->getMessage());
    fail('A database error occurred. Please check the server configuration.', 500);
}
