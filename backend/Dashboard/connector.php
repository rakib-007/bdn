<?php
// connector.php - Single bridge between Front-end & Back-end
session_start();
header('Content-Type: application/json; charset=utf-8');
error_reporting(0);

// Database Connection
$host = 'localhost';
$dbname = 'blood_bank_db';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// --- Register ---
if ($action === 'register') {
    $name         = trim($_POST['name'] ?? '');
    $phone        = trim($_POST['phone'] ?? '');
    $pass         = $_POST['password'] ?? '';
    $blood_group  = $_POST['blood_group'] ?? 'A+';
    $upazila_name = trim($_POST['upazila'] ?? '');
    $availability = $_POST['availability'] ?? 'Available';
    $last_date    = !empty($_POST['last_donation_date']) ? $_POST['last_donation_date'] : null;
    
    // New Fields
    $dob          = !empty($_POST['dob']) ? $_POST['dob'] : null;
    $gender       = $_POST['gender'] ?? 'Male';
    $address      = trim($_POST['address'] ?? '');

    if (empty($name) || empty($phone) || empty($pass)) {
        echo json_encode(['status' => 'error', 'message' => 'প্রয়োজনীয় তথ্য পূরণ করুন।']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT user_id FROM users WHERE phone = ?");
    $stmt->execute([$phone]);
    if ($stmt->fetch()) {
        echo json_encode(['status' => 'error', 'message' => 'এই ফোন নম্বরটি ইতিমধ্যে নিবন্ধিত।']);
        exit;
    }

    $stmtBg = $pdo->prepare("SELECT blood_group_id FROM blood_group WHERE group_name = ? LIMIT 1");
    $stmtBg->execute([$blood_group]);
    $bg_id = $stmtBg->fetchColumn() ?: 1;

    $stmtUpa = $pdo->prepare("SELECT upazila_id FROM upazila WHERE name LIKE ? LIMIT 1");
    $stmtUpa->execute(["%$upazila_name%"]);
    $upazila_id = $stmtUpa->fetchColumn() ?: 1;

    try {
        $pdo->beginTransaction();
        
        // Insert with new fields
        $stmtUser = $pdo->prepare("INSERT INTO users (name, phone, blood_group_id, upazila_id, password, gender, dob, address) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmtUser->execute([$name, $phone, $bg_id, $upazila_id, $pass, $gender, $dob, $address]);
        $userId = $pdo->lastInsertId();

        $stmtProfile = $pdo->prepare("INSERT INTO donor_profile (user_id, last_donation_date, availability) VALUES (?, ?, ?)");
        $stmtProfile->execute([$userId, $last_date, $availability]);
        $pdo->commit();

        echo json_encode(['status' => 'success', 'message' => 'নিবন্ধন সফল হয়েছে! অনুগ্রহ করে সাইন ইন করুন।']);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'ত্রুটি: ' . $e->getMessage()]);
    }
    exit;
}

// --- Login ---
if ($action === 'login') {
    $phone = trim($_POST['phone'] ?? '');
    $pass  = $_POST['password'] ?? '';

    $stmt = $pdo->prepare("SELECT * FROM users WHERE phone = ?");
    $stmt->execute([$phone]);
    $user = $stmt->fetch();

    if ($user && $pass === $user['password']) {
        $_SESSION['user_id']   = $user['user_id'];
        $_SESSION['user_name'] = $user['name'];
        echo json_encode(['status' => 'success', 'message' => 'লগইন সফল হয়েছে!']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'ফোন নম্বর বা পাসওয়ার্ড ভুল হয়েছে।']);
    }
    exit;
}

// --- Check Session ---
if ($action === 'check_auth') {
    if (isset($_SESSION['user_id'])) {
        echo json_encode(['authenticated' => true, 'user_name' => $_SESSION['user_name']]);
    } else {
        echo json_encode(['authenticated' => false]);
    }
    exit;
}

// --- Logout ---
if ($action === 'logout') {
    session_destroy();
    echo json_encode(['status' => 'success']);
    exit;
}

// --- Create Blood Request ---
// --- Create Blood Request ---
if ($action === 'create_request') {
    $patient_name   = trim($_POST['patient_name'] ?? '');
    $patient_age    = intval($_POST['patient_age'] ?? 0);
    $patient_gender = $_POST['patient_gender'] ?? 'Male';
    $patient_phone  = trim($_POST['patient_phone'] ?? '');
    $blood_group_id = intval($_POST['blood_group_id'] ?? 0);
    $hospital_id    = intval($_POST['hospital_id'] ?? 0); 
    $custom_hospital = trim($_POST['custom_hospital'] ?? '');
    $hospital_address = trim($_POST['hospital_address'] ?? '');
    $bags_needed    = intval($_POST['bags_needed'] ?? 1);
    $request_date   = $_POST['request_date'] ?? date('Y-m-d');
    $raw_reason     = trim($_POST['reason'] ?? '');
    $user_id        = $_SESSION['user_id'] ?? 1;

    // Handle Custom Hospital or Fallback
    if ($hospital_id === 0 && !empty($custom_hospital)) {
        $hospital_id = 1; // Default fallback hospital ID in DB
        $hospital_info = "হাসপাতাল: " . $custom_hospital;
    } else {
        $stmtH = $pdo->prepare("SELECT name FROM hospital WHERE hospital_id = ?");
        $stmtH->execute([$hospital_id]);
        $hName = $stmtH->fetchColumn();
        $hospital_info = "হাসপাতাল: " . ($hName ?: 'N/A');
    }

    $full_reason = $hospital_info . " | ঠিকানা: " . $hospital_address . " | বিবরণ: " . $raw_reason;

    if (empty($patient_name) || empty($patient_phone) || empty($blood_group_id) || empty($hospital_address)) {
        echo json_encode(['status' => 'error', 'message' => 'প্রয়োজনীয় তথ্য (রোগীর নাম, ফোন, রক্তের গ্রুপ ও ঠিকানা) প্রদান করুন।']);
        exit;
    }

    $sql = "INSERT INTO blood_request (user_id, hospital_id, blood_group_id, patient_name, patient_age, patient_gender, bags_needed, reason, request_date, patient_phone, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')";

    $stmt = $pdo->prepare($sql);
    $success = $stmt->execute([$user_id, $hospital_id, $blood_group_id, $patient_name, $patient_age, $patient_gender, $bags_needed, $reason_date = $request_date ? $full_reason : $full_reason, $request_date, $patient_phone]);

    if ($success) {
        echo json_encode(['status' => 'success', 'message' => 'রক্তের আবেদন সফলভাবে সম্পন্ন হয়েছে']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'আবেদন করতে ব্যর্থ হয়েছে']);
    }
    exit;
}

// --- Fetch Hospitals by Upazila ---
if ($action === 'get_hospitals_by_upazila') {
    $upazila_id = intval($_GET['upazila_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT hospital_id, name FROM hospital WHERE upazila_id = ? ORDER BY name ASC");
    $stmt->execute([$upazila_id]);
    echo json_encode($stmt->fetchAll());
    exit;
}

// --- Fetch APIs ---
if ($action === 'get_divisions') {
    echo json_encode($pdo->query("SELECT division_id, name FROM division ORDER BY name ASC")->fetchAll());
    exit;
}
if ($action === 'get_districts') {
    $stmt = $pdo->prepare("SELECT district_id, name FROM district WHERE division_id = ? ORDER BY name ASC");
    $stmt->execute([intval($_GET['division_id'] ?? 0)]);
    echo json_encode($stmt->fetchAll());
    exit;
}
if ($action === 'get_upazilas') {
    $stmt = $pdo->prepare("SELECT upazila_id, name FROM upazila WHERE district_id = ? ORDER BY name ASC");
    $stmt->execute([intval($_GET['district_id'] ?? 0)]);
    echo json_encode($stmt->fetchAll());
    exit;
}
if ($action === 'get_blood_groups') {
    echo json_encode($pdo->query("SELECT blood_group_id, group_name FROM blood_group ORDER BY blood_group_id ASC")->fetchAll());
    exit;
}
if ($action === 'search_donors') {
    $bg_id = $_GET['blood_group_id'] ?? '';
    $div_id = $_GET['division_id'] ?? '';
    $dist_id = $_GET['district_id'] ?? '';
    $upa_id = $_GET['upazila_id'] ?? '';

    $query = "SELECT u.name AS donor_name, u.phone AS donor_phone, bg.group_name AS blood_group, up.name AS upazila_name, d.name AS district_name, dp.last_donation_date FROM donor_profile dp JOIN users u ON dp.user_id = u.user_id JOIN blood_group bg ON u.blood_group_id = bg.blood_group_id JOIN upazila up ON u.upazila_id = up.upazila_id JOIN district d ON up.district_id = d.district_id WHERE dp.availability = 'Available'";
    $params = [];

    if ($bg_id) { $query .= " AND bg.blood_group_id = ?"; $params[] = $bg_id; }
    if ($upa_id) { $query .= " AND up.upazila_id = ?"; $params[] = $upa_id; } 
    elseif ($dist_id) { $query .= " AND d.district_id = ?"; $params[] = $dist_id; } 
    elseif ($div_id) { $query .= " AND d.division_id = ?"; $params[] = $div_id; }

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    echo json_encode($stmt->fetchAll());
    exit;
}
echo json_encode([]);
?>
