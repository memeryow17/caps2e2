<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/conn.php';

// For MySQLi compatibility (login.php uses MySQLi syntax)
$conn = getMySQLiConnection();

class LoginManager {
    private $conn;
    
    public function __construct($connection) {
        $this->conn = $connection;
    }
    
    /**
     * Handle user login with comprehensive tracking
     */
    public function handleLogin($data) {
        try {
            $username = isset($data['username']) ? trim($data['username']) : '';
            $password = isset($data['password']) ? trim($data['password']) : '';
            $captcha = isset($data['captcha']) ? trim($data['captcha']) : '';
            $captchaAnswer = isset($data['captchaAnswer']) ? trim($data['captchaAnswer']) : '';
            $route = isset($data['route']) ? trim($data['route']) : '';

            // Validate inputs
            if (empty($username) || empty($password)) {
                return $this->errorResponse("Username and password are required");
            }

            // Verify captcha
            if (empty($captcha) || empty($captchaAnswer) || $captcha !== $captchaAnswer) {
                return $this->errorResponse("Invalid captcha");
            }

            // Get user data
            $user = $this->getUserData($username);
            if (!$user) {
                return $this->errorResponse("Invalid username or password");
            }

            // Check if user is active
            if (strcasecmp($user['status'] ?? '', 'Active') !== 0) {
                return $this->errorResponse("User is inactive. Please contact the administrator.");
            }

            // Verify password
            if (!$this->verifyPassword($password, $user['password'])) {
                return $this->errorResponse("Invalid username or password");
            }

            // Start session
            if (session_status() !== PHP_SESSION_ACTIVE) {
                session_start();
            }

            // Store session data
            $_SESSION['user_id'] = $user['emp_id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['full_name'] = $user['Fname'] . ' ' . $user['Lname'];

            // Determine terminal and location
            $terminalInfo = $this->determineTerminalAndLocation($user['role'], $route);
            
            // Log login to tbl_login
            $loginId = $this->logLoginActivity($user, $terminalInfo);
            
            // Log to activity log
            $this->logActivity($user, 'LOGIN', 'User logged in successfully', $loginId);

            // Handle terminal registration
            $terminalId = $this->registerTerminal($terminalInfo, $user['shift_id']);

            return $this->successResponse([
                'message' => 'Login successful',
                'role' => $user['role'],
                'user_id' => $user['emp_id'],
                'full_name' => $user['Fname'] . ' ' . $user['Lname'],
                'terminal_id' => $terminalId,
                'terminal_name' => $terminalInfo['terminal_name'],
                'location' => $terminalInfo['location'],
                'shift_id' => $user['shift_id'] ?? null
            ]);

        } catch (Exception $e) {
            error_log("Login error: " . $e->getMessage());
            return $this->errorResponse("An error occurred during login: " . $e->getMessage());
        }
    }

    /**
     * Handle user logout with proper tracking
     */
    public function handleLogout($data) {
        try {
            if (session_status() !== PHP_SESSION_ACTIVE) {
                session_start();
            }

            $empId = $_SESSION['user_id'] ?? null;
            $loginId = $_SESSION['login_id'] ?? null;
            
            // Fallback to client-provided emp_id
            if (!$empId && isset($data['emp_id'])) {
                $empId = intval($data['emp_id']);
            }

            // Validate emp_id
            if (!$empId) {
                error_log("Logout failed: No emp_id provided");
                return $this->errorResponse("Employee ID is required for logout");
            }

            error_log("Starting logout process for emp_id: $empId, login_id: " . ($loginId ?: 'NULL'));

            // Update logout time in tbl_login
            $logoutResult = $this->updateLogoutTime($empId, $loginId);
            
            if ($logoutResult === 0) {
                error_log("Logout record creation failed for emp_id: $empId");
                return $this->errorResponse("Failed to create logout record");
            }

            error_log("Logout record created successfully for emp_id: $empId");

            // Log logout activity
            if ($empId) {
                $user = $this->getUserById($empId);
                if ($user) {
                    $this->logActivity($user, 'LOGOUT', 'User logged out from system');
                }
            }

            // Clear session
            $this->clearSession();

            return $this->successResponse(['message' => 'Logout successful', 'emp_id' => $empId]);

        } catch (Exception $e) {
            error_log("Logout error: " . $e->getMessage());
            return $this->errorResponse("An error occurred during logout: " . $e->getMessage());
        }
    }

    /**
     * Generate captcha question and answer
     */
    public function generateCaptcha() {
        try {
            $num1 = rand(1, 10);
            $num2 = rand(1, 10);
            
            $question = "What is {$num1} + {$num2}?";
            $answer = $num1 + $num2;

            return $this->successResponse([
                'question' => $question,
                'answer' => $answer
            ]);

        } catch (Exception $e) {
            return $this->errorResponse("Error generating captcha: " . $e->getMessage());
        }
    }

    /**
     * Get user data by username
     */
    private function getUserData($username) {
        $stmt = $this->conn->prepare("
            SELECT e.emp_id, e.username, e.password, e.status, e.Fname, e.Lname, e.role_id, e.shift_id, r.role 
            FROM tbl_employee e 
            JOIN tbl_role r ON e.role_id = r.role_id 
            WHERE e.username = ?
        ");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }

    /**
     * Get user data by ID
     */
    private function getUserById($empId) {
        $stmt = $this->conn->prepare("
            SELECT e.emp_id, e.username, e.Fname, e.Lname, r.role 
            FROM tbl_employee e 
            JOIN tbl_role r ON e.role_id = r.role_id 
            WHERE e.emp_id = ?
        ");
        $stmt->bind_param("i", $empId);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }

    /**
     * Verify password (handles both hashed and plain text)
     */
    private function verifyPassword($inputPassword, $storedPassword) {
        // Try hashed password first
        if (password_verify($inputPassword, $storedPassword)) {
            return true;
        }
        // Fallback to plain text comparison
        return $inputPassword === $storedPassword;
    }

    /**
     * Determine terminal and location based on role and route
     */
    private function determineTerminalAndLocation($role, $route) {
        $roleLower = strtolower($role ?? '');
        $routeLower = strtolower($route ?? '');

        // Check route first
        if (strpos($routeLower, 'pos_convenience') !== false) {
            return ['location' => 'convenience', 'terminal_name' => 'Convenience POS'];
        } elseif (strpos($routeLower, 'pos_pharmacy') !== false) {
            return ['location' => 'pharmacy', 'terminal_name' => 'Pharmacy POS'];
        } elseif (strpos($routeLower, 'inventory_con') !== false) {
            return ['location' => 'inventory', 'terminal_name' => 'Inventory Terminal'];
        } elseif (strpos($routeLower, 'admin') !== false) {
            return ['location' => 'admin', 'terminal_name' => 'Admin Terminal'];
        }

        // Fallback to role-based determination
        if (strpos($roleLower, 'cashier') !== false || strpos($roleLower, 'pos') !== false) {
            return ['location' => 'convenience', 'terminal_name' => 'Convenience POS'];
        } elseif (strpos($roleLower, 'pharmacist') !== false) {
            return ['location' => 'pharmacy', 'terminal_name' => 'Pharmacy POS'];
        } elseif (strpos($roleLower, 'inventory') !== false) {
            return ['location' => 'inventory', 'terminal_name' => 'Inventory Terminal'];
        } else {
            return ['location' => 'admin', 'terminal_name' => 'Admin Terminal'];
        }
    }

    /**
     * Log login activity to tbl_login table
     */
    private function logLoginActivity($user, $terminalInfo) {
        $stmt = $this->conn->prepare("
            INSERT INTO tbl_login (emp_id, role_id, username, login_time, login_date, ip_address, location, terminal_id, shift_id, status) 
            VALUES (?, ?, ?, CURTIME(), CURDATE(), ?, ?, ?, ?, 'online')
        ");

        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $terminalId = null; // Will be updated after terminal registration
        $shiftId = $user['shift_id'] ?? null;

        $stmt->bind_param("iisssii", 
            $user['emp_id'], 
            $user['role_id'], 
            $user['username'], 
            $ipAddress, 
            $terminalInfo['location'], 
            $terminalId, 
            $shiftId
        );

        $stmt->execute();
        $loginId = $this->conn->insert_id;
        
        // Store login_id in session
        $_SESSION['login_id'] = $loginId;

        return $loginId;
    }

    /**
     * Update logout time in tbl_login by setting status to offline immediately
     * REAL-TIME VERSION - IMMEDIATE STATUS UPDATE
     */
    private function updateLogoutTime($empId, $loginId) {
        if (!$empId) {
            error_log("No emp_id provided for logout");
            return 0;
        }

        $currentTime = date('H:i:s');
        $currentDate = date('Y-m-d');
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        // Get employee info directly
        $stmt = $this->conn->prepare("SELECT username, role_id FROM tbl_employee WHERE emp_id = ?");
        $stmt->bind_param("i", $empId);
        $stmt->execute();
        $result = $stmt->get_result();
        $employee = $result->fetch_assoc();

        if (!$employee) {
            error_log("Employee not found for emp_id: $empId");
            return 0;
        }

        // Find the most recent login record for this employee
        $stmt = $this->conn->prepare("
            SELECT login_id FROM tbl_login 
            WHERE emp_id = ? 
            AND (logout_time IS NULL OR logout_time = '00:00:00')
            AND (status = 'online' OR status IS NULL OR status = '')
            ORDER BY login_id DESC LIMIT 1
        ");
        $stmt->bind_param("i", $empId);
        $stmt->execute();
        $result = $stmt->get_result();
        $loginRecord = $result->fetch_assoc();

        if ($loginRecord) {
            // Update the existing login record to offline status
            $updateStmt = $this->conn->prepare("
                UPDATE tbl_login 
                SET logout_time = ?, logout_date = ?, status = 'offline' 
                WHERE login_id = ?
            ");
            $updateStmt->bind_param("ssi", $currentTime, $currentDate, $loginRecord['login_id']);
            $result = $updateStmt->execute();
            
            if ($result && $updateStmt->affected_rows > 0) {
                error_log("SUCCESS: Updated login record to offline for emp_id: $empId, login_id: {$loginRecord['login_id']}");
                return 1;
            } else {
                error_log("FAILED: Could not update login record for emp_id: $empId - " . $this->conn->error);
                return 0;
            }
        } else {
            // If no active login record found, create a logout record
            $stmt = $this->conn->prepare("
                INSERT INTO tbl_login (
                    emp_id, role_id, username, login_time, login_date, 
                    logout_time, logout_date, status, ip_address, location, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 'offline', ?, ?, NOW())
            ");
            
            $roleId = $employee['role_id'] ?? 1;
            $username = $employee['username'];
            $loginTime = '00:00:00';
            $loginDate = $currentDate;
            $location = 'System Logout';
            
            $stmt->bind_param("iisssssss", 
                $empId, $roleId, $username, $loginTime, $loginDate, 
                $currentTime, $currentDate, $ipAddress, $location
            );
            
            $result = $stmt->execute();
            
            if ($result) {
                $logoutRecordId = $this->conn->insert_id;
                error_log("SUCCESS: Created offline record for emp_id: $empId, logout_id: $logoutRecordId");
                return 1;
            } else {
                error_log("FAILED: Could not create offline record for emp_id: $empId - " . $this->conn->error);
                return 0;
            }
        }
    }

    /**
     * Register terminal and return terminal ID
     */
    private function registerTerminal($terminalInfo, $shiftId) {
        try {
            $stmt = $this->conn->prepare("
                SELECT terminal_id, shift_id FROM tbl_pos_terminal 
                WHERE terminal_name = ? LIMIT 1
            ");
            $stmt->bind_param("s", $terminalInfo['terminal_name']);
            $stmt->execute();
            $result = $stmt->get_result();
            $terminal = $result->fetch_assoc();

            if ($terminal) {
                $terminalId = $terminal['terminal_id'];
                // Update shift if different
                if ($shiftId && $terminal['shift_id'] != $shiftId) {
                    $updateStmt = $this->conn->prepare("
                        UPDATE tbl_pos_terminal SET shift_id = ? WHERE terminal_id = ?
                    ");
                    $updateStmt->bind_param("ii", $shiftId, $terminalId);
                    $updateStmt->execute();
                }
            } else {
                // Create new terminal
                $insertStmt = $this->conn->prepare("
                    INSERT INTO tbl_pos_terminal (terminal_name, shift_id) VALUES (?, ?)
                ");
                $insertStmt->bind_param("si", $terminalInfo['terminal_name'], $shiftId);
                $insertStmt->execute();
                $terminalId = $this->conn->insert_id;
            }

            // Update login record with terminal_id
            if (isset($_SESSION['login_id'])) {
                $updateLoginStmt = $this->conn->prepare("
                    UPDATE tbl_login SET terminal_id = ? WHERE login_id = ?
                ");
                $updateLoginStmt->bind_param("ii", $terminalId, $_SESSION['login_id']);
                $updateLoginStmt->execute();
            }

            return $terminalId;

        } catch (Exception $e) {
            error_log("Terminal registration error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Log activity to tbl_activity_log
     */
    private function logActivity($user, $activityType, $description, $recordId = null) {
        try {
            $stmt = $this->conn->prepare("
                INSERT INTO tbl_activity_log (user_id, username, role, activity_type, activity_description, table_name, record_id, date_created, time_created, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE(), CURTIME(), NOW())
            ");

            $tableName = 'tbl_login';
            $stmt->bind_param("isssssi", 
                $user['emp_id'], 
                $user['username'], 
                $user['role'], 
                $activityType, 
                $description, 
                $tableName, 
                $recordId
            );

            $stmt->execute();

        } catch (Exception $e) {
            error_log("Activity logging error: " . $e->getMessage());
        }
    }

    /**
     * Clear session data
     */
    private function clearSession() {
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, 
                $params['path'], $params['domain'], 
                $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    /**
     * Return success response
     */
    private function successResponse($data) {
        return json_encode(array_merge(['success' => true], $data));
    }

    /**
     * Return error response
     */
    private function errorResponse($message) {
        return json_encode(['success' => false, 'message' => $message]);
    }
}

// Main execution
try {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';

    $loginManager = new LoginManager($conn);

    switch ($action) {
        case 'login':
            echo $loginManager->handleLogin($input);
            break;
            
        case 'logout':
            echo $loginManager->handleLogout($input);
            break;
            
        case 'generate_captcha':
            echo $loginManager->generateCaptcha();
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            break;
    }

} catch (Exception $e) {
    error_log("Login API error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred']);
}
?>
