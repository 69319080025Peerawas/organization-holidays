<?php
declare(strict_types=1);

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

/*
 * ระบบปฏิทินวันหยุดภายในองค์กร (Organization Holiday Calendar)
 * Laragon Default: Host 127.0.0.1 | User: root | Pass: (ว่าง)
 */
$dbHost = '127.0.0.1';
$dbName = 'organization_holidays';
$dbUser = 'root';
$dbPass = '';

try {
    // 1. สร้างฐานข้อมูลอัตโนมัติหากยังไม่มี
    $pdoInit = new PDO("mysql:host={$dbHost};charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    $pdoInit->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdoInit = null;

    // 2. เชื่อมต่อฐานข้อมูล
    $pdo = new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // 3. สร้างตาราง users
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(100) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            fullname VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // 4. สร้างตาราง holidays
    // เงื่อนไขสำคัญ: วันเดียวกันมีวันหยุดที่ยังใช้งานได้ไม่เกินหนึ่งรายการ
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS holidays (
            id INT AUTO_INCREMENT PRIMARY KEY,
            holiday_date DATE NOT NULL,
            holiday_name VARCHAR(255) NOT NULL,
            holiday_type VARCHAR(100) NOT NULL,
            note TEXT NULL,
            status TINYINT(1) NOT NULL DEFAULT 1,
            active_date DATE GENERATED ALWAYS AS (
                CASE WHEN status = 1 THEN holiday_date ELSE NULL END
            ) STORED,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_active_date (active_date),
            INDEX idx_holiday_year (holiday_date),
            INDEX idx_holiday_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // 5. สร้างบัญชีผู้ดูแลระบบเริ่มต้น (admin / admin12345)
    $checkAdmin = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $checkAdmin->execute(['admin']);
    if (!$checkAdmin->fetch()) {
        $stmt = $pdo->prepare("INSERT INTO users (username, password, fullname) VALUES (?, ?, ?)");
        $stmt->execute(['admin', password_hash('admin12345', PASSWORD_DEFAULT), 'ผู้ดูแลระบบ (HR)']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    exit(
        '<div style="font-family:sans-serif;padding:30px;max-width:600px;margin:auto">'
        . '<h2 style="color:#b91c1c">ไม่สามารถเชื่อมต่อฐานข้อมูลได้</h2>'
        . '<p>กรุณาตรวจสอบว่าเปิด MySQL ใน Laragon แล้ว</p>'
        . '<pre style="background:#f3f4f6;padding:12px;border-radius:8px">'
        . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre></div>'
    );
}

// --- Helper Functions ---
function h(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirectTo(string $url = 'index.php'): never {
    header("Location: {$url}");
    exit;
}

function csrfToken(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function checkCsrf(): void {
    if (!isset($_POST['csrf']) || !hash_equals($_SESSION['csrf'] ?? '', (string)$_POST['csrf'])) {
        http_response_code(419);
        exit('คำขอไม่ถูกต้อง กรุณารีเฟรชหน้าจอแล้วลองใหม่');
    }
}

/**
 * ตรวจสอบเงื่อนไขสำคัญ:
 * "วันเดียวกันมีวันหยุดที่ยังใช้งานได้ไม่เกินหนึ่งรายการ และเมื่อแก้ไขต้องไม่นับรายการตนเองเป็นข้อมูลซ้ำ"
 */
function hasActiveHolidayConflict(PDO $pdo, string $date, int $excludeId = 0): bool {
    $stmt = $pdo->prepare("
        SELECT id 
        FROM holidays 
        WHERE holiday_date = ? 
          AND status = 1 
          AND id != ?
        LIMIT 1
    ");
    $stmt->execute([$date, $excludeId]);
    return (bool)$stmt->fetch();
}

function formatThaiDate(string $dateStr): string {
    $ts = strtotime($dateStr);
    if (!$ts) return $dateStr;
    $days = ['อาทิตย์', 'จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์'];
    $months = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    return 'วัน' . $days[(int)date('w', $ts)] . 'ที่ ' . (int)date('j', $ts) . ' ' . $months[(int)date('n', $ts)] . ' ' . ((int)date('Y', $ts) + 543);
}

// --- ตัวแปรควบคุมหน้าเว็บ ---
$error = '';
$success = '';
$isLoggedIn = isset($_SESSION['user']);

$year = (int)($_GET['year'] ?? date('Y'));
if ($year < 1900 || $year > 3000) $year = (int)date('Y');

$filterType = trim((string)($_GET['type'] ?? ''));
$searchQuery = trim((string)($_GET['q'] ?? ''));

// --- จัดการคำขอ POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = (string)($_POST['action'] ?? '');

    // 1. เข้าสู่ระบบ
    if ($action === 'login') {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            session_regenerate_id(true);
            $_SESSION['user'] = [
                'id' => $user['id'],
                'fullname' => $user['fullname'],
                'username' => $user['username']
            ];
            $_SESSION['flash'] = 'เข้าสู่ระบบสำเร็จ';
            redirectTo("index.php?year={$year}");
        } else {
            $error = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
        }
    }

    // 2. ออกจากระบบ
    if ($action === 'logout' && $isLoggedIn) {
        $_SESSION = [];
        session_destroy();
        redirectTo('index.php');
    }

    // 3. การจัดการข้อมูลวันหยุด (เพิ่ม / แก้ไข / สลับสถานะ / ลบ)
    if ($isLoggedIn) {
        try {
            // บันทึก (เพิ่มใหม่ หรือ แก้ไข)
            if ($action === 'save') {
                $id     = (int)($_POST['id'] ?? 0);
                $date   = trim((string)($_POST['holiday_date'] ?? ''));
                $name   = trim((string)($_POST['holiday_name'] ?? ''));
                $type   = trim((string)($_POST['holiday_type'] ?? ''));
                $note   = trim((string)($_POST['note'] ?? ''));
                $status = (int)($_POST['status'] ?? 1);

                $validDate = DateTime::createFromFormat('Y-m-d', $date);
                if (!$validDate || $validDate->format('Y-m-d') !== $date || $name === '' || $type === '') {
                    throw new RuntimeException('กรุณากรอกข้อมูล วันที่ ชื่อวันหยุด และประเภทวันหยุด ให้ครบถ้วน');
                }
                if (!in_array($status, [0, 1], true)) {
                    $status = 1;
                }

                // ตรวจสอบเงื่อนไข: หากตั้งสถานะเป็น "ใช้งาน (1)" ต้องไม่มีรายการอื่นที่ใช้งานอยู่ในวันเดียวกัน (ยกเว้น id ของตนเอง)
                if ($status === 1 && hasActiveHolidayConflict($pdo, $date, $id)) {
                    throw new RuntimeException('ไม่สามารถบันทึกได้: วันที่ ' . date('d/m/Y', strtotime($date)) . ' มีวันหยุดที่เปิดใช้งานอยู่แล้ว (1 วันมีวันหยุดที่ใช้งานได้ไม่เกิน 1 รายการ)');
                }

                $targetYear = (int)$validDate->format('Y');

                if ($id > 0) {
                    $stmt = $pdo->prepare("
                        UPDATE holidays 
                        SET holiday_date = ?, holiday_name = ?, holiday_type = ?, note = ?, status = ? 
                        WHERE id = ?
                    ");
                    $stmt->execute([$date, $name, $type, $note, $status, $id]);
                    $_SESSION['flash'] = 'แก้ไขข้อมูลวันหยุดเรียบร้อยแล้ว';
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO holidays (holiday_date, holiday_name, holiday_type, note, status) 
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$date, $name, $type, $note, $status]);
                    $_SESSION['flash'] = 'เพิ่มวันหยุดเรียบร้อยแล้ว';
                }
                redirectTo("index.php?year={$targetYear}");
            }

            // สลับสถานะ ใช้งาน / ไม่ใช้งาน
            if ($action === 'toggle_status') {
                $id = (int)($_POST['id'] ?? 0);
                $stmt = $pdo->prepare("SELECT * FROM holidays WHERE id = ?");
                $stmt->execute([$id]);
                $row = $stmt->fetch();

                if ($row) {
                    $newStatus = ((int)$row['status'] === 1) ? 0 : 1;
                    // ถ้ากำลังจะเปลี่ยนจาก "ไม่ใช้งาน (0)" เป็น "ใช้งาน (1)" ต้องเช็กว่าวันนั้นมีรายการอื่นใช้งานอยู่หรือไม่ (ไม่นับ id ตนเอง)
                    if ($newStatus === 1 && hasActiveHolidayConflict($pdo, $row['holiday_date'], $id)) {
                        throw new RuntimeException('ไม่สามารถเปิดใช้งานได้: วันที่ ' . date('d/m/Y', strtotime($row['holiday_date'])) . ' มีรายการวันหยุดอื่นที่เปิดใช้งานอยู่แล้ว');
                    }
                    $upd = $pdo->prepare("UPDATE holidays SET status = ? WHERE id = ?");
                    $upd->execute([$newStatus, $id]);
                    $_SESSION['flash'] = 'อัปเดตสถานะเรียบร้อยแล้ว';
                }
                redirectTo("index.php?year={$year}");
            }

            // ลบข้อมูล
            if ($action === 'delete') {
                $id = (int)($_POST['id'] ?? 0);
                $stmt = $pdo->prepare("DELETE FROM holidays WHERE id = ?");
                $stmt->execute([$id]);
                $_SESSION['flash'] = 'ลบรายการวันหยุดเรียบร้อยแล้ว';
                redirectTo("index.php?year={$year}");
            }
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $error = 'วันที่นี้มีวันหยุดที่เปิดใช้งานอยู่แล้ว (1 วันมีวันหยุดที่ใช้งานได้ไม่เกิน 1 รายการ)';
            } else {
                $error = 'เกิดข้อผิดพลาดในการบันทึกข้อมูล: ' . $e->getMessage();
            }
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }
    }
}

if (isset($_SESSION['flash'])) {
    $success = (string)$_SESSION['flash'];
    unset($_SESSION['flash']);
}

// --- ดึงข้อมูลวันหยุดตามปีที่ค้นหา ---
$sql = "SELECT * FROM holidays WHERE YEAR(holiday_date) = ?";
$params = [$year];

if (!$isLoggedIn) {
    $sql .= " AND status = 1";
}
if ($filterType !== '') {
    $sql .= " AND holiday_type = ?";
    $params[] = $filterType;
}
if ($searchQuery !== '') {
    $sql .= " AND (holiday_name LIKE ? OR note LIKE ?)";
    $params[] = "%{$searchQuery}%";
    $params[] = "%{$searchQuery}%";
}
$sql .= " ORDER BY holiday_date ASC, id ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$holidays = $stmt->fetchAll();

// สถิติของปีที่เลือก
$statStmt = $pdo->prepare("
    SELECT 
        COUNT(*) AS total_count,
        SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) AS active_count
    FROM holidays 
    WHERE YEAR(holiday_date) = ?
");
$statStmt->execute([$year]);
$statData = $statStmt->fetch();
$totalCount = (int)($statData['total_count'] ?? 0);
$activeCount = (int)($statData['active_count'] ?? 0);
$inactiveCount = $totalCount - $activeCount;

// ดึงข้อมูลสำหรับแก้ไข
$editItem = null;
if ($isLoggedIn && isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM holidays WHERE id = ?");
    $stmt->execute([(int)$_GET['edit']]);
    $editItem = $stmt->fetch() ?: null;
}

$holidayTypes = ['วันหยุดราชการ', 'วันหยุดประเพณี', 'วันหยุดองค์กร', 'วันหยุดพิเศษ'];
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ระบบปฏิทินวันหยุดภายในองค์กร</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
body { background: #f1f5f9; font-family: 'Sarabun', sans-serif; color: #1e293b; }
.navbar-org { background: linear-gradient(135deg, #0f294a, #1d5ba0); color: #fff; }
.card { border: 0; border-radius: 14px; box-shadow: 0 4px 16px rgba(15, 41, 74, 0.06); }
.stat-num { font-size: 28px; font-weight: 700; }
.table thead th { background: #f8fafc; color: #475569; font-size: 14px; white-space: nowrap; }
</style>
</head>
<body>

<nav class="navbar-org py-3 mb-4 shadow-sm">
  <div class="container d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div>
      <h5 class="mb-0 fw-bold">📅 ระบบปฏิทินวันหยุดภายในองค์กร</h5>
      <small class="text-white-50">Organization Holiday Calendar · ปี ค.ศ. <?=h($year)?> (พ.ศ. <?=h($year + 543)?>)</small>
    </div>
    <div>
      <?php if ($isLoggedIn): ?>
        <span class="me-3 small">ผู้ใช้งาน: <strong><?=h($_SESSION['user']['fullname'])?></strong></span>
        <form method="post" class="d-inline">
          <input type="hidden" name="csrf" value="<?=h(csrfToken())?>">
          <input type="hidden" name="action" value="logout">
          <button type="submit" class="btn btn-sm btn-outline-light">ออกจากระบบ</button>
        </form>
      <?php else: ?>
        <a href="#login-box" class="btn btn-sm btn-warning fw-semibold">🔒 เข้าสู่ระบบผู้ดูแล (HR)</a>
      <?php endif; ?>
    </div>
  </div>
</nav>

<div class="container pb-5">

  <?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show">
      ✅ <?=h($success)?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show">
      ⚠️ <?=h($error)?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <!-- กล่องเข้าสู่ระบบ -->
  <?php if (!$isLoggedIn): ?>
    <div class="card p-4 mb-4 border-start border-4 border-primary" id="login-box">
      <div class="row align-items-center g-3">
        <div class="col-lg-5">
          <h6 class="fw-bold mb-1">เข้าสู่ระบบเพื่อจัดการวันหยุด (เพิ่ม / แก้ไข / ลบ)</h6>
          <p class="text-secondary small mb-0">
            บัญชีทดสอบ: ชื่อผู้ใช้ <code>admin</code> / รหัสผ่าน <code>admin12345</code>
          </p>
        </div>
        <div class="col-lg-7">
          <form method="post" class="row g-2">
            <input type="hidden" name="csrf" value="<?=h(csrfToken())?>">
            <input type="hidden" name="action" value="login">
            <div class="col-sm-4">
              <input class="form-control" name="username" placeholder="ชื่อผู้ใช้" value="admin" required>
            </div>
            <div class="col-sm-4">
              <input class="form-control" type="password" name="password" placeholder="รหัสผ่าน" required>
            </div>
            <div class="col-sm-4 d-grid">
              <button type="submit" class="btn btn-primary fw-semibold">เข้าสู่ระบบ</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <!-- สรุปจำนวนวันหยุดประจำปี -->
  <div class="row g-3 mb-4">
    <div class="col-md-4">
      <div class="card p-3">
        <div class="text-secondary small">วันหยุดทั้งหมดในปี <?=h($year)?> (พ.ศ. <?=h($year + 543)?>)</div>
        <div class="stat-num text-dark"><?=h($totalCount)?> <span class="fs-6 fw-normal text-secondary">รายการ</span></div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card p-3">
        <div class="text-secondary small">สถานะใช้งาน (Active)</div>
        <div class="stat-num text-success"><?=h($activeCount)?> <span class="fs-6 fw-normal text-secondary">วัน</span></div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card p-3">
        <div class="text-secondary small">สถานะไม่ใช้งาน (Inactive)</div>
        <div class="stat-num text-secondary"><?=h($inactiveCount)?> <span class="fs-6 fw-normal text-secondary">รายการ</span></div>
      </div>
    </div>
  </div>

  <!-- ฟอร์มเพิ่ม / แก้ไขวันหยุด (แสดงเมื่อล็อกอินแล้ว) -->
  <?php if ($isLoggedIn): ?>
    <div class="card p-4 mb-4 border-top border-4 border-primary" id="holiday-form">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="fw-bold mb-0"><?= $editItem ? '✏️ แก้ไขข้อมูลวันหยุด (ID #' . (int)$editItem['id'] . ')' : '➕ เพิ่มวันหยุดใหม่' ?></h5>
        <?php if ($editItem): ?>
          <a href="index.php?year=<?=h($year)?>" class="btn btn-sm btn-outline-secondary">ยกเลิกการแก้ไข</a>
        <?php endif; ?>
      </div>

      <form method="post">
        <input type="hidden" name="csrf" value="<?=h(csrfToken())?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?=h($editItem['id'] ?? 0)?>">

        <div class="row g-3">
          <div class="col-md-3">
            <label class="form-label fw-semibold">วันที่ (ค.ศ.) <span class="text-danger">*</span></label>
            <input class="form-control" type="date" name="holiday_date" value="<?=h($editItem['holiday_date'] ?? '')?>" required>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">ชื่อวันหยุด <span class="text-danger">*</span></label>
            <input class="form-control" name="holiday_name" value="<?=h($editItem['holiday_name'] ?? '')?>" placeholder="เช่น วันขึ้นปีใหม่" required>
          </div>
          <div class="col-md-3">
            <label class="form-label fw-semibold">ประเภทวันหยุด <span class="text-danger">*</span></label>
            <select class="form-select" name="holiday_type" required>
              <?php $selectedType = $editItem['holiday_type'] ?? 'วันหยุดราชการ'; ?>
              <?php foreach ($holidayTypes as $type): ?>
                <option value="<?=h($type)?>" <?=$selectedType === $type ? 'selected' : ''?>><?=h($type)?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label fw-semibold">สถานะใช้งาน <span class="text-danger">*</span></label>
            <select class="form-select" name="status">
              <option value="1" <?=((int)($editItem['status'] ?? 1) === 1) ? 'selected' : ''?>>ใช้งาน</option>
              <option value="0" <?=((int)($editItem['status'] ?? 1) === 0) ? 'selected' : ''?>>ไม่ใช้งาน</option>
            </select>
          </div>
          <div class="col-md-9">
            <label class="form-label fw-semibold">หมายเหตุ</label>
            <input class="form-control" name="note" value="<?=h($editItem['note'] ?? '')?>" placeholder="รายละเอียดเพิ่มเติม (ถ้ามี)">
          </div>
          <div class="col-md-3 d-flex align-items-end">
            <button type="submit" class="btn btn-primary w-100 fw-semibold">
              <?= $editItem ? '💾 บันทึกการแก้ไข' : '➕ บันทึกวันหยุด' ?>
            </button>
          </div>
        </div>
      </form>
    </div>
  <?php endif; ?>

  <!-- ส่วนค้นหาตามปี และตารางแสดงผล -->
  <div class="card p-4">
    <form method="get" class="row g-2 align-items-end mb-4">
      <div class="col-sm-3 col-md-2">
        <label class="form-label small fw-semibold mb-1">ค้นหาตามปี (ค.ศ.)</label>
        <input class="form-control" type="number" name="year" min="1900" max="3000" value="<?=h($year)?>" required>
      </div>
      <div class="col-sm-4 col-md-3">
        <label class="form-label small fw-semibold mb-1">ประเภทวันหยุด</label>
        <select class="form-select" name="type">
          <option value="">-- ทั้งหมด --</option>
          <?php foreach ($holidayTypes as $t): ?>
            <option value="<?=h($t)?>" <?=$filterType === $t ? 'selected' : ''?>><?=h($t)?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-5 col-md-4">
        <label class="form-label small fw-semibold mb-1">คำค้นหา (ชื่อวันหยุด / หมายเหตุ)</label>
        <input class="form-control" type="text" name="q" value="<?=h($searchQuery)?>" placeholder="พิมพ์ชื่อวันหยุด...">
      </div>
      <div class="col-md-3 d-flex gap-2">
        <button type="submit" class="btn btn-primary flex-grow-1">🔍 ค้นหา</button>
        <?php if ($filterType !== '' || $searchQuery !== '' || $year !== (int)date('Y')): ?>
          <a href="index.php" class="btn btn-outline-secondary">รีเซ็ต</a>
        <?php endif; ?>
      </div>
    </form>

    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead>
          <tr>
            <th>วันที่</th>
            <th>ชื่อวันหยุด</th>
            <th>ประเภทวันหยุด</th>
            <th>หมายเหตุ</th>
            <th>สถานะใช้งาน</th>
            <?php if ($isLoggedIn): ?>
              <th class="text-end">จัดการ</th>
            <?php endif; ?>
          </tr>
        </thead>
        <tbody>
        <?php if (!$holidays): ?>
          <tr>
            <td colspan="<?=$isLoggedIn ? 6 : 5?>" class="text-center text-secondary py-4">
              ไม่พบข้อมูลวันหยุดในปี ค.ศ. <?=h($year)?> (พ.ศ. <?=h($year + 543)?>)
            </td>
          </tr>
        <?php endif; ?>

        <?php foreach ($holidays as $item): ?>
          <tr>
            <td class="text-nowrap">
              <div class="fw-semibold"><?=h(date('d/m/Y', strtotime($item['holiday_date'])))?></div>
              <small class="text-secondary"><?=h(formatThaiDate($item['holiday_date']))?></small>
            </td>
            <td class="fw-semibold"><?=h($item['holiday_name'])?></td>
            <td><span class="badge bg-light text-dark border"><?=h($item['holiday_type'])?></span></td>
            <td class="text-secondary small"><?=h($item['note'] ?: '-')?></td>
            <td>
              <?php if ($isLoggedIn): ?>
                <form method="post" class="d-inline">
                  <input type="hidden" name="csrf" value="<?=h(csrfToken())?>">
                  <input type="hidden" name="action" value="toggle_status">
                  <input type="hidden" name="id" value="<?=(int)$item['id']?>">
                  <?php if ((int)$item['status'] === 1): ?>
                    <button type="submit" class="btn btn-sm btn-success" title="คลิกเพื่อเปลี่ยนเป็นไม่ใช้งาน">ใช้งาน</button>
                  <?php else: ?>
                    <button type="submit" class="btn btn-sm btn-secondary" title="คลิกเพื่อเปลี่ยนเป็นใช้งาน">ไม่ใช้งาน</button>
                  <?php endif; ?>
                </form>
              <?php else: ?>
                <span class="badge bg-success">ใช้งาน</span>
              <?php endif; ?>
            </td>

            <?php if ($isLoggedIn): ?>
              <td class="text-end text-nowrap">
                <a class="btn btn-sm btn-outline-primary" href="?year=<?=h($year)?>&edit=<?=(int)$item['id']?>#holiday-form">แก้ไข</a>
                <form method="post" class="d-inline" onsubmit="return confirm('ยืนยันการลบรายการนี้หรือไม่?')">
                  <input type="hidden" name="csrf" value="<?=h(csrfToken())?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?=(int)$item['id']?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger">ลบ</button>
                </form>
              </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
