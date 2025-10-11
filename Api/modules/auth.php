<?php
// Authentication Module - Handles login, logout, captcha, employee management, and activity logging

function handle_login($conn, $data) {
    try {
        $username = isset($data['username']) ? trim($data['username']) : '';
        $password = isset($data['password']) ? trim($data['password']) : '';
        $captcha = isset($data['captcha']) ? trim($data['captcha']) : '';
        $captchaAnswer = isset($data['captchaAnswer']) ? trim($data['captchaAnswer']) : '';

        // Validate inputs
        if (empty($username) || empty($password)) {
            echo json_encode(["success" => false, "message" => "Username and password are required"]);
            exit;
        }

        // Verify captcha
        if (empty($captcha) || empty($captchaAnswer) || $captcha !== $captchaAnswer) {
            echo json_encode(["success" => false, "message" => "Invalid captcha"]);
            exit;
        }

        // Check if user exists (regardless of status)
        $stmt = $conn->prepare("
            SELECT e.emp_id, e.username, e.password, e.status, e.Fname, e.Lname, e.role_id, e.shift_id, r.role
            FROM tbl_employee e
            JOIN tbl_role r ON e.role_id = r.role_id
            WHERE e.username = :username
        ");
        $stmt->bindParam(":username", $username, PDO::PARAM_STR);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // If user exists but is inactive, return a specific message
        if ($user && strcasecmp($user['status'] ?? '', 'Active') !== 0) {
            echo json_encode(["success" => false, "message" => "User is inactive. Please contact the administrator."]);
            return;
        }

        // Check password - handle both hashed and plain text passwords
        $passwordValid = false;
        if ($user) {
            // First try to verify as hashed password
            if (password_verify($password, $user['password'])) {
                $passwordValid = true;
            }
            // If that fails, check if it's a plain text password (for backward compatibility)
            elseif ($password === $user['password']) {
                $passwordValid = true;
            }
        }

        if ($user && $passwordValid) {
            // Start session and store user data
            session_start();
            $_SESSION['user_id'] = $user['emp_id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['full_name'] = $user['Fname'] . ' ' . $user['Lname'];

            // Log login activity to tbl_login
            try {
                $loginStmt = $conn->prepare("
                    INSERT INTO tbl_login (emp_id, role_id, username, login_time, login_date, ip_address, location, terminal_id, shift_id)
                    VALUES (:emp_id, :role_id, :username, CURTIME(), CURDATE(), :ip_address, :location, :terminal_id, :shift_id)
                ");

                $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

                $loginStmt->bindParam(':emp_id', $user['emp_id'], PDO::PARAM_INT);
                $loginStmt->bindParam(':role_id', $user['role_id'], PDO::PARAM_INT);
                $loginStmt->bindParam(':username', $user['username'], PDO::PARAM_STR);
                $loginStmt->bindParam(':ip_address', $ip_address, PDO::PARAM_STR);
                $loginStmt->bindParam(':location', $location_label, PDO::PARAM_STR);
                $loginStmt->bindParam(':terminal_id', $terminal_id, PDO::PARAM_INT);
                $loginStmt->bindParam(':shift_id', $user['shift_id'], PDO::PARAM_INT);

                $loginStmt->execute();

                // Store login_id in session for logout tracking
                $_SESSION['login_id'] = $conn->lastInsertId();
                $login_id_inserted = $_SESSION['login_id'];

                // Log login activity to activity log
                try {
                    $activityStmt = $conn->prepare("
                        INSERT INTO tbl_activity_log (user_id, username, role, activity_type, activity_description, table_name, record_id, date_created, time_created, created_at)
                        VALUES (:user_id, :username, :role, :activity_type, :activity_description, :table_name, :record_id, CURDATE(), CURTIME(), NOW())
                    ");

                    $activityStmt->execute([
                        ':user_id' => $user['emp_id'],
                        ':username' => $user['username'],
                        ':role' => $user['role'],
                        ':activity_type' => 'LOGIN',
                        ':activity_description' => 'User logged in successfully',
                        ':table_name' => 'tbl_login',
                        ':record_id' => $login_id_inserted
                    ]);
                } catch (Exception $activityError) {
                    error_log("Activity logging error: " . $activityError->getMessage());
                }

            } catch (Exception $loginLogError) {
                error_log("Login logging error: " . $loginLogError->getMessage());
                // Continue with login even if logging fails
            }

            // Terminal/location handling: prefer explicit route, else infer from role
            $route = strtolower(trim($data['route'] ?? ''));
            $location_label = null;
            $terminal_name = null;
            if ($route !== '') {
                if (strpos($route, 'pos_convenience') !== false) { $location_label = 'convenience'; $terminal_name = 'Convenience POS'; }
                elseif (strpos($route, 'pos_pharmacy') !== false) { $location_label = 'pharmacy'; $terminal_name = 'Pharmacy POS'; }
                elseif (strpos($route, 'inventory_con') !== false) { $location_label = 'inventory'; $terminal_name = 'Inventory Terminal'; }
                elseif (strpos($route, 'admin') !== false) { $location_label = 'admin'; $terminal_name = 'Admin Terminal'; }
            }
            if (!$terminal_name) {
                $roleLower = strtolower((string)($user['role'] ?? ''));
                if (strpos($roleLower, 'cashier') !== false || strpos($roleLower, 'pos') !== false) { $location_label = 'convenience'; $terminal_name = 'Convenience POS'; }
                elseif (strpos($roleLower, 'pharmacist') !== false) { $location_label = 'pharmacy'; $terminal_name = 'Pharmacy POS'; }
                elseif (strpos($roleLower, 'inventory') !== false) { $location_label = 'inventory'; $terminal_name = 'Inventory Terminal'; }
                else { $location_label = 'admin'; $terminal_name = 'Admin Terminal'; }
            }

            $terminal_id = null;
            if ($terminal_name) {
                try {
                    // Ensure terminal exists and update shift
                    $termSel = $conn->prepare("SELECT terminal_id, shift_id FROM tbl_pos_terminal WHERE terminal_name = :name LIMIT 1");
                    $termSel->execute([':name' => $terminal_name]);
                    $term = $termSel->fetch(PDO::FETCH_ASSOC);
                    $user_shift_id = $user['shift_id'] ?? null;
                    if ($term) {
                        $terminal_id = (int)$term['terminal_id'];
                        if ($user_shift_id && (int)$term['shift_id'] !== (int)$user_shift_id) {
                            $upd = $conn->prepare("UPDATE tbl_pos_terminal SET shift_id = :shift WHERE terminal_id = :tid");
                            $upd->execute([':shift' => $user_shift_id, ':tid' => $terminal_id]);
                        }
                    } else {
                        $ins = $conn->prepare("INSERT INTO tbl_pos_terminal (terminal_name, shift_id) VALUES (:name, :shift)");
                        $ins->execute([':name' => $terminal_name, ':shift' => $user_shift_id]);
                        $terminal_id = (int)$conn->lastInsertId();
                    }

                    // Optionally annotate login row with location/terminal if columns exist
                    if (!empty($login_id_inserted)) {
                        try {
                            $tryUpd = $conn->prepare("UPDATE tbl_login SET location = :loc WHERE login_id = :lid");
                            $tryUpd->execute([':loc' => $location_label, ':lid' => $login_id_inserted]);
                        } catch (Exception $ignore) {}
                        try {
                            $tryUpd2 = $conn->prepare("UPDATE tbl_login SET terminal_id = :tid WHERE login_id = :lid");
                            $tryUpd2->execute([':tid' => $terminal_id, ':lid' => $login_id_inserted]);
                        } catch (Exception $ignore) {}
                        try {
                            $tryUpd3 = $conn->prepare("UPDATE tbl_login SET shift_id = :sid WHERE login_id = :lid");
                            $tryUpd3->execute([':sid' => $user_shift_id, ':lid' => $login_id_inserted]);
                        } catch (Exception $ignore) {}
                    }
                } catch (Exception $terminalError) {
                    error_log('Terminal handling error: ' . $terminalError->getMessage());
                }
            }

            // Log login activity to system activity logs
            try {
                $logStmt = $conn->prepare("INSERT INTO tbl_activity_log (user_id, username, role, activity_type, activity_description, table_name, record_id, date_created, time_created, created_at) VALUES (:user_id, :username, :role, :activity_type, :activity_description, :table_name, :record_id, CURDATE(), CURTIME(), NOW()), CURTIME())");
                $logStmt->execute([
                    ':user_id' => $user['emp_id'],
                    ':username' => $user['username'],
                    ':employee_name' => $user['Fname'] . ' ' . $user['Lname'],
                    ':role' => $user['role'],
                    ':activity_type' => 'LOGIN',
                    ':activity_description' => "User logged in successfully from {$terminal_name}",
                    ':module' => 'Authentication',
                    ':action' => 'LOGIN',
                    ':location' => $terminal_name,
                    ':terminal_id' => $terminal_id
                ]);
            } catch (Exception $activityLogError) {
                error_log("Activity logging error: " . $activityLogError->getMessage());
            }

            echo json_encode([
                "success" => true,
                "message" => "Login successful",
                "role" => $user['role'],
                "user_id" => $user['emp_id'],
                "full_name" => $user['Fname'] . ' ' . $user['Lname'],
                "terminal_id" => $terminal_id,
                "terminal_name" => $terminal_name,
                "location" => $location_label,
                "shift_id" => $user['shift_id'] ?? null
            ]);
        } else {
            echo json_encode(["success" => false, "message" => "Invalid username or password"]);
        }

    } catch (Exception $e) {
        echo json_encode(["success" => false, "message" => "An error occurred: " . $e->getMessage()]);
    }
}
    try {
        $username = isset($data['username']) ? trim($data['username']) : '';
        $password = isset($data['password']) ? trim($data['password']) : '';
        $captcha = isset($data['captcha']) ? trim($data['captcha']) : '';
        $captchaAnswer = isset($data['captchaAnswer']) ? trim($data['captchaAnswer']) : '';

        // Validate inputs
        if (empty($username) || empty($password)) {
            echo json_encode(["success" => false, "message" => "Username and password are required"]);
            exit;
        }

        // Verify captcha
        if (empty($captcha) || empty($captchaAnswer) || $captcha !== $captchaAnswer) {
            echo json_encode(["success" => false, "message" => "Invalid captcha"]);
            exit;
        }

        // Check if user exists (regardless of status)
        $stmt = $conn->prepare("
            SELECT e.emp_id, e.username, e.password, e.status, e.Fname, e.Lname, e.role_id, e.shift_id, r.role
            FROM tbl_employee e
            JOIN tbl_role r ON e.role_id = r.role_id
            WHERE e.username = :username
        ");
        $stmt->bindParam(":username", $username, PDO::PARAM_STR);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // If user exists but is inactive, return a specific message
        if ($user && strcasecmp($user['status'] ?? '', 'Active') !== 0) {
            echo json_encode(["success" => false, "message" => "User is inactive. Please contact the administrator."]);
            return;
        }

        // Check password - handle both hashed and plain text passwords
        $passwordValid = false;
        if ($user) {
            // First try to verify as hashed password
            if (password_verify($password, $user['password'])) {
                $passwordValid = true;
            }
            // If that fails, check if it's a plain text password (for backward compatibility)
            elseif ($password === $user['password']) {
                $passwordValid = true;
            }
        }

        if ($user && $passwordValid) {
            // Start session and store user data
            session_start();
            $_SESSION['user_id'] = $user['emp_id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['full_name'] = $user['Fname'] . ' ' . $user['Lname'];

            // Log login activity to tbl_login
            try {
                $loginStmt = $conn->prepare("
                    INSERT INTO tbl_login (emp_id, role_id, username, login_time, login_date, ip_address, location, terminal_id, shift_id)
                    VALUES (:emp_id, :role_id, :username, CURTIME(), CURDATE(), :ip_address, :location, :terminal_id, :shift_id)
                ");

                $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

                $loginStmt->bindParam(':emp_id', $user['emp_id'], PDO::PARAM_INT);
                $loginStmt->bindParam(':role_id', $user['role_id'], PDO::PARAM_INT);
                $loginStmt->bindParam(':username', $user['username'], PDO::PARAM_STR);
                $loginStmt->bindParam(':ip_address', $ip_address, PDO::PARAM_STR);
                $loginStmt->bindParam(':location', $location_label, PDO::PARAM_STR);
                $loginStmt->bindParam(':terminal_id', $terminal_id, PDO::PARAM_INT);
                $loginStmt->bindParam(':shift_id', $user['shift_id'], PDO::PARAM_INT);

                $loginStmt->execute();

                // Store login_id in session for logout tracking
                $_SESSION['login_id'] = $conn->lastInsertId();
                $login_id_inserted = $_SESSION['login_id'];

                // Log login activity to activity log
                try {
                    $activityStmt = $conn->prepare("
                        INSERT INTO tbl_activity_log (user_id, username, role, activity_type, activity_description, table_name, record_id, date_created, time_created, created_at)
                        VALUES (:user_id, :username, :role, :activity_type, :activity_description, :table_name, :record_id, CURDATE(), CURTIME(), NOW())
                    ");

                    $activityStmt->execute([
                        ':user_id' => $user['emp_id'],
                        ':username' => $user['username'],
                        ':role' => $user['role'],
                        ':activity_type' => 'LOGIN',
                        ':activity_description' => 'User logged in successfully',
                        ':table_name' => 'tbl_login',
                        ':record_id' => $login_id_inserted
                    ]);
                } catch (Exception $activityError) {
                    error_log("Activity logging error: " . $activityError->getMessage());
                }

            } catch (Exception $loginLogError) {
                error_log("Login logging error: " . $loginLogError->getMessage());
                // Continue with login even if logging fails
            }

            // Terminal/location handling: prefer explicit route, else infer from role
            $route = strtolower(trim($data['route'] ?? ''));
            $location_label = null;
            $terminal_name = null;
            if ($route !== '') {
                if (strpos($route, 'pos_convenience') !== false) { $location_label = 'convenience'; $terminal_name = 'Convenience POS'; }
                elseif (strpos($route, 'pos_pharmacy') !== false) { $location_label = 'pharmacy'; $terminal_name = 'Pharmacy POS'; }
                elseif (strpos($route, 'inventory_con') !== false) { $location_label = 'inventory'; $terminal_name = 'Inventory Terminal'; }
                elseif (strpos($route, 'admin') !== false) { $location_label = 'admin'; $terminal_name = 'Admin Terminal'; }
            }
            if (!$terminal_name) {
                $roleLower = strtolower((string)($user['role'] ?? ''));
                if (strpos($roleLower, 'cashier') !== false || strpos($roleLower, 'pos') !== false) { $location_label = 'convenience'; $terminal_name = 'Convenience POS'; }
                elseif (strpos($roleLower, 'pharmacist') !== false) { $location_label = 'pharmacy'; $terminal_name = 'Pharmacy POS'; }
                elseif (strpos($roleLower, 'inventory') !== false) { $location_label = 'inventory'; $terminal_name = 'Inventory Terminal'; }
                else { $location_label = 'admin'; $terminal_name = 'Admin Terminal'; }
            }

            $terminal_id = null;
            if ($terminal_name) {
                try {
                    // Ensure terminal exists and update shift
                    $termSel = $conn->prepare("SELECT terminal_id, shift_id FROM tbl_pos_terminal WHERE terminal_name = :name LIMIT 1");
                    $termSel->execute([':name' => $terminal_name]);
                    $term = $termSel->fetch(PDO::FETCH_ASSOC);
                    $user_shift_id = $user['shift_id'] ?? null;
                    if ($term) {
                        $terminal_id = (int)$term['terminal_id'];
                        if ($user_shift_id && (int)$term['shift_id'] !== (int)$user_shift_id) {
                            $upd = $conn->prepare("UPDATE tbl_pos_terminal SET shift_id = :shift WHERE terminal_id = :tid");
                            $upd->execute([':shift' => $user_shift_id, ':tid' => $terminal_id]);
                        }
                    } else {
                        $ins = $conn->prepare("INSERT INTO tbl_pos_terminal (terminal_name, shift_id) VALUES (:name, :shift)");
                        $ins->execute([':name' => $terminal_name, ':shift' => $user_shift_id]);
                        $terminal_id = (int)$conn->lastInsertId();
                    }

                    // Optionally annotate login row with location/terminal if columns exist
                    if (!empty($login_id_inserted)) {
                        try {
                            $tryUpd = $conn->prepare("UPDATE tbl_login SET location = :loc WHERE login_id = :lid");
                            $tryUpd->execute([':loc' => $location_label, ':lid' => $login_id_inserted]);
                        } catch (Exception $ignore) {}
                        try {
                            $tryUpd2 = $conn->prepare("UPDATE tbl_login SET terminal_id = :tid WHERE login_id = :lid");
                            $tryUpd2->execute([':tid' => $terminal_id, ':lid' => $login_id_inserted]);
                        } catch (Exception $ignore) {}
                        try {
                            $tryUpd3 = $conn->prepare("UPDATE tbl_login SET shift_id = :sid WHERE login_id = :lid");
                            $tryUpd3->execute([':sid' => $user_shift_id, ':lid' => $login_id_inserted]);
                        } catch (Exception $ignore) {}
                    }
                } catch (Exception $terminalError) {
                    error_log('Terminal handling error: ' . $terminalError->getMessage());
                }
            }

            // Log login activity to system activity logs
            try {
                $logStmt = $conn->prepare("INSERT INTO tbl_activity_log (user_id, username, role, activity_type, activity_description, table_name, record_id, date_created, time_created, created_at) VALUES (:user_id, :username, :role, :activity_type, :activity_description, :table_name, :record_id, CURDATE(), CURTIME(), NOW()), CURTIME())");
                $logStmt->execute([
                    ':user_id' => $user['emp_id'],
                    ':username' => $user['username'],
                    ':employee_name' => $user['Fname'] . ' ' . $user['Lname'],
                    ':role' => $user['role'],
                    ':activity_type' => 'LOGIN',
                    ':activity_description' => "User logged in successfully from {$terminal_name}",
                    ':module' => 'Authentication',
                    ':action' => 'LOGIN',
                    ':location' => $terminal_name,
                    ':terminal_id' => $terminal_id
                ]);
            } catch (Exception $activityLogError) {
                error_log("Activity logging error: " . $activityLogError->getMessage());
            }

            echo json_encode([
                "success" => true,
                "message" => "Login successful",
                "role" => $user['role'],
                "user_id" => $user['emp_id'],
                "full_name" => $user['Fname'] . ' ' . $user['Lname'],
                "terminal_id" => $terminal_id,
                "terminal_name" => $terminal_name,
                "location" => $location_label,
                "shift_id" => $user['shift_id'] ?? null
            ]);
        } else {
            echo json_encode(["success" => false, "message" => "Invalid username or password"]);
        }

    } catch (Exception $e) {
        echo json_encode(["success" => false, "message" => "An error occurred: " . $e->getMessage()]);
    }
}

function handle_logout($conn, $data) {
    try {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $empId = $_SESSION['user_id'] ?? null;
        $loginId = $_SESSION['login_id'] ?? null;
        // Fallback to client-provided emp_id when session cookies aren't present (CORS, different port, etc.)
        if (!$empId && isset($data['emp_id'])) {
            $empId = intval($data['emp_id']);
        }

        try {
            $updated = 0;
            if ($loginId && $empId) {
                // Update the known session login row
                $logoutStmt = $conn->prepare("UPDATE tbl_login SET logout_time = CURTIME(), logout_date = CURDATE() WHERE login_id = :login_id AND emp_id = :emp_id");
                $logoutStmt->bindParam(':login_id', $loginId, PDO::PARAM_INT);
                $logoutStmt->bindParam(':emp_id', $empId, PDO::PARAM_INT);
                $logoutStmt->execute();
                $updated = $logoutStmt->rowCount();
                // error_log removed for production: logout update by session
            }
            if ($updated === 0 && $empId) {
                // Fallback: find the most recent OPEN login record for this employee
                $findStmt = $conn->prepare("SELECT login_id FROM tbl_login WHERE emp_id = :emp_id AND (logout_time IS NULL OR logout_time = '00:00:00') ORDER BY login_id DESC LIMIT 1");
                $findStmt->bindParam(':emp_id', $empId, PDO::PARAM_INT);
                $findStmt->execute();
                $row = $findStmt->fetch(PDO::FETCH_ASSOC);
                if ($row && isset($row['login_id'])) {
                    $fallbackLogout = $conn->prepare("UPDATE tbl_login SET logout_time = CURTIME(), logout_date = CURDATE() WHERE login_id = :login_id");
                    $fallbackLogout->bindParam(':login_id', $row['login_id'], PDO::PARAM_INT);
                    $fallbackLogout->execute();
                    $updated = $fallbackLogout->rowCount();
                    // error_log removed for production: logout update by open row
                }
            }
            if ($updated === 0 && $empId) {
                // Final fallback: update the most recent row for this employee
                $findStmt = $conn->prepare("SELECT login_id FROM tbl_login WHERE emp_id = :emp_id ORDER BY login_id DESC LIMIT 1");
                $findStmt->bindParam(':emp_id', $empId, PDO::PARAM_INT);
                $findStmt->execute();
                $last = $findStmt->fetch(PDO::FETCH_ASSOC);
                if ($last && isset($last['login_id'])) {
                    $updAny = $conn->prepare("UPDATE tbl_login SET logout_time = CURTIME(), logout_date = CURDATE() WHERE login_id = :login_id");
                    $updAny->bindParam(':login_id', $last['login_id'], PDO::PARAM_INT);
                    $updAny->execute();
                    // error_log removed for production: forced logout update
                }
            }
        } catch (Exception $logoutLogError) {
            error_log("Logout logging error");
        }

        // Log logout activity to system activity logs
        if ($empId) {
            try {
                // Get employee details for logging
                $empStmt = $conn->prepare("SELECT username, Fname, Lname, role FROM tbl_employee WHERE emp_id = ?");
                $empStmt->execute([$empId]);
                $empData = $empStmt->fetch(PDO::FETCH_ASSOC);

                if ($empData) {
                    $logStmt = $conn->prepare("INSERT INTO tbl_activity_log (user_id, username, role, activity_type, activity_description, table_name, record_id, date_created, time_created, created_at) VALUES (:user_id, :username, :role, :activity_type, :activity_description, :table_name, :record_id, CURDATE(), CURTIME(), NOW()), CURTIME())");
                    $logStmt->execute([
                        ':user_id' => $empId,
                        ':username' => $empData['username'],
                        ':employee_name' => $empData['Fname'] . ' ' . $empData['Lname'],
                        ':role' => $empData['role'],
                        ':activity_type' => 'LOGOUT',
                        ':activity_description' => 'User logged out from system',
                        ':module' => 'Authentication',
                        ':action' => 'LOGOUT'
                    ]);
                }
            } catch (Exception $activityLogError) {
                error_log("Activity logging error: " . $activityLogError->getMessage());
            }
        }

        // Clear session only after writing logout record
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();

        echo json_encode([
            'success' => true,
            'message' => 'Logout successful'
        ]);

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'An error occurred during logout: ' . $e->getMessage()]);
    }
}

function handle_generate_captcha($conn, $data) {
    try {
        // Generate simple addition captcha only
        $num1 = rand(1, 15);
        $num2 = rand(1, 15);
        $answer = $num1 + $num2;

        $question = "What is $num1 + $num2?";

        // Ensure answer is always a number
        $answer = (int)$answer;

        // Log for debugging
        // error_log removed for production: captcha generated

        echo json_encode([
            "success" => true,
            "question" => $question,
            "answer" => $answer
        ]);
    } catch (Exception $e) {
        echo json_encode([
            "success" => false,
            "message" => "Error generating captcha: " . $e->getMessage()
        ]);
    }
}

function handle_add_employee($conn, $data) {
    try {
        // Extract and sanitize input data
        $fname = isset($data['fname'])&& !empty($data['fname']) ? trim($data['fname']) : '';
        $mname = isset($data['mname']) && !empty($data['mname'])? trim($data['mname']) : '';
        $lname = isset($data['lname']) && !empty($data['lname'])? trim($data['lname']) : '';
        $email = isset($data['email']) ? trim($data['email']) : '';
        $contact = isset($data['contact_num']) ? trim($data['contact_num']) : '';
        $role_id = isset($data['role_id']) ? trim($data['role_id']) : '';
        $shift_id = isset($data['shift_id']) && $data['shift_id'] !== null && $data['shift_id'] !== '' ? (int)$data['shift_id'] : null;
        $username = isset($data['username']) ? trim($data['username']) : '';
        $password = isset($data['password']) ? trim($data['password']) : '';
        $age = isset($data['age']) ? trim($data['age']) : '';
        $address = isset($data['address']) ? trim($data['address']) : '';
        $status = isset($data['status']) ? trim($data['status']) : 'Active';
        $gender = isset($data['gender']) ? trim($data['gender']) : '';
        $birthdate = isset($data['birthdate']) ? trim($data['birthdate']) : '';

        // Only require shift_id for cashier (3) and pharmacist (2)
        if (($role_id == 2 || $role_id == 3) && empty($shift_id)) {
            echo json_encode(["success" => false, "message" => "Shift is required."]);
            exit;
        }

        // Hash the password
        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

        // Prepare the SQL statement
        $stmt = $conn->prepare("
            INSERT INTO tbl_employee (
                Fname, Mname, Lname, email, contact_num, role_id, shift_id,
                username, password, age, address, status,gender,birthdate
            ) VALUES (
                :fname, :mname, :lname, :email, :contact_num, :role_id, :shift_id,
                :username, :password, :age, :address, :status, :gender, :birthdate
            )
        ");

        // Bind parameters
        $stmt->bindParam(":fname", $fname, PDO::PARAM_STR);
        $stmt->bindParam(":mname", $mname, PDO::PARAM_STR);
        $stmt->bindParam(":lname", $lname, PDO::PARAM_STR);
        $stmt->bindParam(":email", $email, PDO::PARAM_STR);
        $stmt->bindParam(":contact_num", $contact, PDO::PARAM_STR);
        $stmt->bindParam(":role_id", $role_id, PDO::PARAM_INT);
        if ($shift_id !== null) {
            $stmt->bindValue(":shift_id", $shift_id, PDO::PARAM_INT);
        } else {
            $stmt->bindValue(":shift_id", null, PDO::PARAM_NULL);
        }
        $stmt->bindParam(":username", $username, PDO::PARAM_STR);
        $stmt->bindParam(":password", $hashedPassword, PDO::PARAM_STR);
        $stmt->bindParam(":age", $age, PDO::PARAM_INT);
        $stmt->bindParam(":address", $address, PDO::PARAM_STR);
        $stmt->bindParam(":status", $status, PDO::PARAM_STR);
        $stmt->bindParam(":gender", $gender, PDO::PARAM_STR);
        $stmt->bindParam(":birthdate", $birthdate, PDO::PARAM_STR);

        // Execute the statement
        if ($stmt->execute()) {
            echo json_encode(["success" => true, "message" => "Employee added successfully"]);
        } else {
            echo json_encode(["success" => false, "message" => "Failed to add employee"]);
        }

    } catch (Exception $e) {
        echo json_encode(["success" => false, "message" => "An error occurred: " . $e->getMessage()]);
    }
}

function handle_display_employee($conn, $data) {
    try {
        $stmt = $conn->prepare("SELECT emp_id,Fname,Mname,Lname,email,contact_num,role_id,shift_id,username,age,address,status,gender,birthdate FROM tbl_employee");
        $stmt->execute();
        $employee = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($employee) {
            echo json_encode([
                "success" => true,
                "employees" => $employee
            ]);
        } else {
            echo json_encode([
                "success" => true,
                "employees" => [],
                "message" => "No employees found"
            ]);
        }
    } catch (Exception $e) {
        echo json_encode([
            "success" => false,
            "message" => "Database error: " . $e->getMessage(),
            "employees" => []
        ]);
    }
}

function handle_update_employee_status($conn, $data) {
    try {
        $emp_id = isset($data['id']) ? trim($data['id']) : '';
        $newStatus = isset($data['status']) ? trim($data['status']) : '';

        $stmt = $conn->prepare("UPDATE tbl_employee SET status = :status WHERE emp_id = :id");
        $stmt->bindParam(":status", $newStatus, PDO::PARAM_STR);
        $stmt->bindParam(":id", $emp_id, PDO::PARAM_INT);

        if ($stmt->execute()) {
            echo json_encode(["success" => true, "message" => "Status updated successfully"]);
        } else {
            echo json_encode(["success" => false, "message" => "Failed to update status"]);
        }
    } catch (Exception $e) {
        echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
    }
}

function handle_get_login_records($conn, $data) {
    try {
        $stmt = $conn->prepare("
            SELECT l.*, e.username, r.role
            FROM tbl_login l
            JOIN tbl_employee e ON l.emp_id = e.emp_id
            LEFT JOIN tbl_role r ON l.role_id = r.role_id
            ORDER BY l.login_id DESC
            LIMIT 10
        ");
        $stmt->execute();
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'records' => $records
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function handle_get_users($conn, $data) {
    try {
        $stmt = $conn->prepare("
            SELECT e.emp_id, e.username, e.status, r.role
            FROM tbl_employee e
            LEFT JOIN tbl_role r ON e.role_id = r.role_id
            ORDER BY e.emp_id
        ");
        $stmt->execute();
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'users' => $users
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function handle_get_activity_records($conn, $data) {
    try {
        $stmt = $conn->prepare("
            SELECT * FROM tbl_activity_log
            WHERE activity_type IN ('LOGIN', 'LOGOUT')
            ORDER BY created_at DESC
            LIMIT 10
        ");
        $stmt->execute();
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'records' => $records
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function handle_register_terminal_route($conn, $data) {
    try {
        if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
        $empId = $_SESSION['user_id'] ?? ($data['emp_id'] ?? null);
        $route = strtolower(trim($data['route'] ?? ''));
        if (!$empId || $route === '') {
            echo json_encode(['success' => false, 'message' => 'Missing emp_id or route']);
            return;
        }

        // Get employee shift
        $emp = null;
        try {
            $st = $conn->prepare("SELECT shift_id, role_id FROM tbl_employee WHERE emp_id = :id LIMIT 1");
            $st->execute([':id' => $empId]);
            $emp = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}
        $user_shift_id = $emp['shift_id'] ?? null;

        // Map route → terminal/location
        $location_label = 'admin';
        $terminal_name = 'Admin Terminal';
        if (strpos($route, 'pos_convenience') !== false) { $location_label = 'convenience'; $terminal_name = 'Convenience POS'; }
        elseif (strpos($route, 'pos_pharmacy') !== false) { $location_label = 'pharmacy'; $terminal_name = 'Pharmacy POS'; }
        elseif (strpos($route, 'inventory_con') !== false) { $location_label = 'inventory'; $terminal_name = 'Inventory Terminal'; }
        elseif (strpos($route, 'admin') !== false) { $location_label = 'admin'; $terminal_name = 'Admin Terminal'; }

        // Ensure terminal exists and update shift
        $termSel = $conn->prepare("SELECT terminal_id, shift_id FROM tbl_pos_terminal WHERE terminal_name = :name LIMIT 1");
        $termSel->execute([':name' => $terminal_name]);
        $term = $termSel->fetch(PDO::FETCH_ASSOC);
        if ($term) {
            $terminal_id = (int)$term['terminal_id'];
            if ($user_shift_id && (int)$term['shift_id'] !== (int)$user_shift_id) {
                $upd = $conn->prepare("UPDATE tbl_pos_terminal SET shift_id = :shift WHERE terminal_id = :tid");
                $upd->execute([':shift' => $user_shift_id, ':tid' => $terminal_id]);
            }
        } else {
            $ins = $conn->prepare("INSERT INTO tbl_pos_terminal (terminal_name, shift_id) VALUES (:name, :shift)");
            $ins->execute([':name' => $terminal_name, ':shift' => $user_shift_id]);
            $terminal_id = (int)$conn->lastInsertId();
        }

        // Annotate most recent open login row
        try {
            $findStmt = $conn->prepare("SELECT login_id FROM tbl_login WHERE emp_id = :emp AND (logout_time IS NULL OR logout_time = '00:00:00') ORDER BY login_id DESC LIMIT 1");
            $findStmt->execute([':emp' => $empId]);
            $row = $findStmt->fetch(PDO::FETCH_ASSOC);
            if ($row && isset($row['login_id'])) {
                try { $upd1 = $conn->prepare("UPDATE tbl_login SET terminal_id = :tid WHERE login_id = :lid"); $upd1->execute([':tid' => $terminal_id, ':lid' => $row['login_id']]); } catch (Exception $e) {}
                try { $upd2 = $conn->prepare("UPDATE tbl_login SET location = :loc WHERE login_id = :lid"); $upd2->execute([':loc' => $location_label, ':lid' => $row['login_id']]); } catch (Exception $e) {}
                try { if ($user_shift_id) { $upd3 = $conn->prepare("UPDATE tbl_login SET shift_id = :sid WHERE login_id = :lid"); $upd3->execute([':sid' => $user_shift_id, ':lid' => $row['login_id']]); } } catch (Exception $e) {}
            }
        } catch (Exception $e) {}

        echo json_encode(['success' => true, 'data' => [
            'terminal_id' => $terminal_id,
            'terminal_name' => $terminal_name,
            'location' => $location_label,
            'shift_id' => $user_shift_id
        ]]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
}

function handle_get_login_activity($conn, $data) {
    try {
        $limit = isset($data['limit']) ? intval($data['limit']) : 200;
        $search = isset($data['search']) ? trim($data['search']) : '';
        $date_from = isset($data['date_from']) ? trim($data['date_from']) : '';
        $date_to = isset($data['date_to']) ? trim($data['date_to']) : '';

        $clauses = [];
        $params = [];

        if ($search !== '') {
            $clauses[] = '(l.username LIKE ? OR e.Fname LIKE ? OR e.Lname LIKE ?)';
            $term = "%$search%";
            $params[] = $term; $params[] = $term; $params[] = $term;
        }
        if ($date_from !== '') { $clauses[] = 'l.login_date >= ?'; $params[] = $date_from; }
        if ($date_to !== '') { $clauses[] = 'l.login_date <= ?'; $params[] = $date_to; }

        $whereSql = count($clauses) ? ('WHERE ' . implode(' AND ', $clauses)) : '';

        $sql = "
            SELECT
                l.login_id, l.emp_id, l.role_id, l.username,
                l.login_time, l.login_date, l.logout_time, l.logout_date,
                l.ip_address,
                e.Fname, e.Lname, r.role,
                -- Compute terminal/location label without requiring extra columns
                CASE
                    WHEN LOWER(r.role) LIKE '%admin%' THEN 'Admin Terminal'
                    WHEN LOWER(r.role) LIKE '%cashier%' OR LOWER(r.role) LIKE '%pos%' THEN 'Convenience POS'
                    WHEN LOWER(r.role) LIKE '%pharmacist%' THEN 'Pharmacy POS'
                    WHEN LOWER(r.role) LIKE '%inventory%' THEN 'Inventory Terminal'
                    ELSE 'Admin Terminal'
                END AS terminal_name
            FROM tbl_login l
            LEFT JOIN tbl_employee e ON l.emp_id = e.emp_id
            LEFT JOIN tbl_role r ON l.role_id = r.role_id
            $whereSql
            ORDER BY l.login_id DESC
            LIMIT $limit
        ";

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $rowCount = is_array($rows) ? count($rows) : 0;

        // If no rows, try a fallback simple query (helps diagnose join/data issues)
        $fallback = [];
        if ($rowCount === 0) {
            $fb = $conn->prepare("SELECT * FROM tbl_login ORDER BY login_id DESC LIMIT 5");
            $fb->execute();
            $fallback = $fb->fetchAll(PDO::FETCH_ASSOC);
        }

        // Debug: log how many rows were found
        error_log('[get_login_activity] rows=' . $rowCount . ', fallback=' . count($fallback));

        echo json_encode(['success' => true, 'data' => $rows, 'fallback' => $fallback]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage(), 'data' => []]);
    }
}

function handle_get_login_activity_count($conn, $data) {
    try {
        // Count today's logins and logouts (each recorded row counts once; rows with today's logout but older login also counted)
        $stmt = $conn->prepare("SELECT
                SUM(CASE WHEN login_date = CURDATE() THEN 1 ELSE 0 END) AS logins_today,
                SUM(CASE WHEN logout_date = CURDATE() THEN 1 ELSE 0 END) AS logouts_today
            FROM tbl_login");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['logins_today' => 0, 'logouts_today' => 0];
        $total = (int)$row['logins_today'] + (int)$row['logouts_today'];
        echo json_encode(['success' => true, 'data' => ['logins_today' => (int)$row['logins_today'], 'logouts_today' => (int)$row['logouts_today'], 'total' => $total]]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage(), 'data' => ['logins_today' => 0, 'logouts_today' => 0, 'total' => 0]]);
    }
}

function handle_log_activity($conn, $data) {
    try {
        // Ensure comprehensive activity logs table exists
        $conn->exec("CREATE TABLE IF NOT EXISTS `tbl_activity_log` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `user_id` int(11) DEFAULT NULL,
            `username` varchar(255) DEFAULT NULL,
            `employee_name` varchar(255) DEFAULT NULL,
            `role` varchar(100) DEFAULT NULL,
            `activity_type` varchar(100) NOT NULL,
            `activity_description` text DEFAULT NULL,
            `module` varchar(100) DEFAULT NULL COMMENT 'Module where activity occurred (POS, Inventory, Admin, etc.)',
            `action` varchar(100) DEFAULT NULL COMMENT 'Specific action performed',
            `table_name` varchar(255) DEFAULT NULL COMMENT 'Database table affected',
            `record_id` int(11) DEFAULT NULL COMMENT 'ID of the affected record',
            `old_values` json DEFAULT NULL COMMENT 'Previous values (for updates)',
            `new_values` json DEFAULT NULL COMMENT 'New values (for updates)',
            `ip_address` varchar(45) DEFAULT NULL,
            `user_agent` text DEFAULT NULL,
            `location` varchar(255) DEFAULT NULL COMMENT 'Physical location or terminal',
            `terminal_id` varchar(100) DEFAULT NULL,
            `session_id` varchar(255) DEFAULT NULL,
            `date_created` date NOT NULL,
            `time_created` time NOT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_user_id` (`user_id`),
            KEY `idx_username` (`username`),
            KEY `idx_activity_type` (`activity_type`),
            KEY `idx_module` (`module`),
            KEY `idx_date_created` (`date_created`),
            KEY `idx_created_at` (`created_at`),
            KEY `idx_table_record` (`table_name`, `record_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Comprehensive system activity logs';");

        // Get user data from session or provided data
        $user_id = isset($data['user_id']) ? $data['user_id'] : (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null);
        $username = isset($data['username']) ? trim($data['username']) : (isset($_SESSION['username']) ? $_SESSION['username'] : null);
        $role = isset($data['role']) ? trim($data['role']) : (isset($_SESSION['role']) ? $_SESSION['role'] : null);
        $employee_name = isset($data['employee_name']) ? trim($data['employee_name']) : (isset($_SESSION['full_name']) ? $_SESSION['full_name'] : null);

        $activity_type = isset($data['activity_type']) ? trim($data['activity_type']) : '';
        $activity_description = isset($data['description']) ? trim($data['description']) : null;
        $module = isset($data['module']) ? trim($data['module']) : 'System';
        $action = isset($data['action']) ? trim($data['action']) : $activity_type;
        $table_name = isset($data['table_name']) ? trim($data['table_name']) : null;
        $record_id = isset($data['record_id']) ? $data['record_id'] : null;
        $location = isset($data['location']) ? trim($data['location']) : 'System';
        $terminal_id = isset($data['terminal_id']) ? trim($data['terminal_id']) : null;
        $ip_address = isset($data['ip_address']) ? $data['ip_address'] : ($_SERVER['REMOTE_ADDR'] ?? null);
        $user_agent = isset($data['user_agent']) ? $data['user_agent'] : ($_SERVER['HTTP_USER_AGENT'] ?? null);
        $session_id = isset($data['session_id']) ? $data['session_id'] : (session_id() ?: null);

        if ($activity_type === '') {
            echo json_encode(["success" => false, "message" => "activity_type is required"]);
            return;
        }

        $date_created = date('Y-m-d');
        $time_created = date('H:i:s');

        $stmt = $conn->prepare("INSERT INTO tbl_activity_log (user_id, username, employee_name, role, activity_type, activity_description, module, action, table_name, record_id, location, terminal_id, ip_address, user_agent, session_id, date_created, time_created) VALUES (:user_id, :username, :employee_name, :role, :activity_type, :activity_description, :module, :action, :table_name, :record_id, :location, :terminal_id, :ip_address, :user_agent, :session_id, :date_created, :time_created)");

        $stmt->bindValue(':user_id', $user_id !== null && $user_id !== '' ? $user_id : null, $user_id !== null && $user_id !== '' ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':username', $username !== null && $username !== '' ? $username : null, $username !== null && $username !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':employee_name', $employee_name !== null && $employee_name !== '' ? $employee_name : null, $employee_name !== null && $employee_name !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':role', $role !== null && $role !== '' ? $role : null, $role !== null && $role !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindParam(':activity_type', $activity_type, PDO::PARAM_STR);
        $stmt->bindValue(':activity_description', $activity_description !== null && $activity_description !== '' ? $activity_description : null, $activity_description !== null && $activity_description !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':module', $module !== null && $module !== '' ? $module : null, $module !== null && $module !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':action', $action !== null && $action !== '' ? $action : null, $action !== null && $action !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':table_name', $table_name !== null && $table_name !== '' ? $table_name : null, $table_name !== null && $table_name !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':record_id', $record_id !== null && $record_id !== '' ? $record_id : null, $record_id !== null && $record_id !== '' ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':location', $location !== null && $location !== '' ? $location : null, $location !== null && $location !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':terminal_id', $terminal_id !== null && $terminal_id !== '' ? $terminal_id : null, $terminal_id !== null && $terminal_id !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':ip_address', $ip_address !== null && $ip_address !== '' ? $ip_address : null, $ip_address !== null && $ip_address !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':user_agent', $user_agent !== null && $user_agent !== '' ? $user_agent : null, $user_agent !== null && $user_agent !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':session_id', $session_id !== null && $session_id !== '' ? $session_id : null, $session_id !== null && $session_id !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindParam(':date_created', $date_created, PDO::PARAM_STR);
        $stmt->bindParam(':time_created', $time_created, PDO::PARAM_STR);
        $stmt->execute();

        echo json_encode(["success" => true, "message" => "Activity logged successfully"]);
    } catch (Exception $e) {
        echo json_encode(["success" => false, "message" => "Error logging activity: " . $e->getMessage()]);
    }
}

function handle_get_activity_logs($conn, $data) {
    try {
        // Ensure table exists for safe reads
        $conn->exec("CREATE TABLE IF NOT EXISTS tbl_activity_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            username VARCHAR(255) NULL,
            role VARCHAR(100) NULL,
            activity_type VARCHAR(100) NOT NULL,
            activity_description TEXT NULL,
            table_name VARCHAR(255) NULL,
            record_id INT NULL,
            date_created DATE NOT NULL,
            time_created TIME NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $limit = isset($data['limit']) ? max(1, intval($data['limit'])) : 200;
        $search = isset($data['search']) ? trim($data['search']) : '';
        $date_from = isset($data['date_from']) ? trim($data['date_from']) : '';
        $date_to = isset($data['date_to']) ? trim($data['date_to']) : '';

        $where = [];
        $params = [];
        if ($search !== '') {
            $where[] = "(username LIKE :s OR role LIKE :s OR activity_type LIKE :s OR activity_description LIKE :s)";
            $params[':s'] = '%' . $search . '%';
        }
        if ($date_from !== '') {
            $where[] = "date_created >= :df";
            $params[':df'] = $date_from;
        }
        if ($date_to !== '') {
            $where[] = "date_created <= :dt";
            $params[':dt'] = $date_to;
        }
        $whereSql = count($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

        $sql = "SELECT id, user_id, username, role, activity_type, activity_description, table_name, record_id, date_created, time_created, created_at FROM tbl_activity_log $whereSql ORDER BY created_at DESC, id DESC LIMIT :lim";
        $stmt = $conn->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(["success" => true, "data" => $rows]);
    } catch (Exception $e) {
        echo json_encode(["success" => false, "message" => "Error getting activity logs: " . $e->getMessage(), "data" => []]);
    }
}

function handle_get_all_logs($conn, $data) {
    try {
        $limit = isset($data['limit']) ? max(1, intval($data['limit'])) : 500;
        $search = isset($data['search']) ? trim($data['search']) : '';
        $date_from = isset($data['date_from']) ? trim($data['date_from']) : '';
        $date_to = isset($data['date_to']) ? trim($data['date_to']) : '';

        // Ensure table exists and add sample data if empty
        $conn->exec("CREATE TABLE IF NOT EXISTS tbl_activity_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            username VARCHAR(255) NULL,
            role VARCHAR(100) NULL,
            activity_type VARCHAR(100) NOT NULL,
            activity_description TEXT NULL,
            table_name VARCHAR(255) NULL,
            record_id INT NULL,
            date_created DATE NOT NULL,
            time_created TIME NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // Check if table is empty and add sample data
        $countStmt = $conn->prepare("SELECT COUNT(*) as count FROM tbl_activity_log");
        $countStmt->execute();
        $count = $countStmt->fetch(PDO::FETCH_ASSOC)['count'];

        if ($count == 0) {
            // Add comprehensive sample data for testing
            $sampleData = [
                ['LOGIN', 'User logged in successfully from Main Office terminal', 'admin', 'Admin'],
                ['POS_SALE_SAVED', 'POS Sale completed: Transaction TXN-2024001 - ₱250.00 (CASH, 3 items) at Convenience POS Terminal 1', 'cashier1', 'Cashier'],
                ['STOCK_ADJUSTMENT_CREATED', 'Stock Addition: 100 units of Medicine ABC (Reason: New delivery from supplier)', 'inventory', 'Inventory'],
                ['INVENTORY_TRANSFER_CREATED', 'Transfer created from Warehouse to Convenience Store: 5 products (Medicine, Vitamins)', 'inventory', 'Inventory'],
                ['USER_CREATE', 'Created new employee: John Doe (john.doe) with role Cashier', 'admin', 'Admin'],
                ['STOCK_IN', 'Stock In: 50 units of Product XYZ received from Supplier ABC', 'warehouse', 'Warehouse'],
                ['STOCK_OUT', 'Stock Out: 25 units of Product ABC sold via POS', 'pos_system', 'POS'],
                ['LOGOUT', 'User logged out from Main Office terminal', 'cashier1', 'Cashier'],
                ['NAVIGATION', 'User navigated to Reports section', 'manager1', 'Manager'],
                ['USER_UPDATE', 'Updated employee profile: Jane Smith (jane.smith)', 'admin', 'Admin'],
                ['WAREHOUSE_STOCK_UPDATED', 'Warehouse stock updated: Product ID 45, Quantity: 50, Batch: BTH-001', 'inventory', 'Inventory'],
                ['POS_SALE_SAVED', 'POS Sale completed: Transaction TXN-2024002 - ₱180.50 (GCASH, 2 items) at Pharmacy POS', 'cashier2', 'Cashier'],
                ['STOCK_ADJUSTMENT_CREATED', 'Stock Correction: Adjusted Product ID 123 quantity from 10 to 15 (Reason: Found additional stock)', 'inventory', 'Inventory'],
                ['INVENTORY_TRANSFER_CREATED', 'Transfer completed: 3 products moved from Convenience Store to Pharmacy', 'inventory', 'Inventory'],
                ['LOGIN', 'User logged in from Pharmacy terminal', 'pharmacist1', 'Pharmacist']
            ];

            $insertSample = $conn->prepare("INSERT INTO tbl_activity_log (activity_type, activity_description, username, role, date_created, time_created) VALUES (?, ?, ?, ?, CURDATE(), CURTIME())");
            foreach ($sampleData as $sample) {
                $insertSample->execute($sample);
            }
        }

        // Fetch activity logs
        $paramsAct = [];
        $whereAct = [];
        if ($search !== '') {
            $whereAct[] = "(username LIKE :s OR role LIKE :s OR activity_type LIKE :s OR activity_description LIKE :s)";
            $paramsAct[':s'] = '%' . $search . '%';
        }
        if ($date_from !== '') { $whereAct[] = "date_created >= :df"; $paramsAct[':df'] = $date_from; }
        if ($date_to !== '') { $whereAct[] = "date_created <= :dt"; $paramsAct[':dt'] = $date_to; }
        $whereActSql = count($whereAct) ? ('WHERE ' . implode(' AND ', $whereAct)) : '';
        $stmtAct = $conn->prepare("SELECT date_created, time_created, username, role, activity_type AS action, activity_description AS description FROM tbl_activity_log $whereActSql ORDER BY created_at DESC, id DESC LIMIT :limAct");
        foreach ($paramsAct as $k => $v) { $stmtAct->bindValue($k, $v); }
        $limAct = min($limit, 300);
        $stmtAct->bindValue(':limAct', $limAct, PDO::PARAM_INT);
        $stmtAct->execute();
        $activity = $stmtAct->fetchAll(PDO::FETCH_ASSOC);

        // Fetch inventory movement history
        $movementData = [];
        try {
            $paramsMovement = [];
            $whereMovement = [];
            if ($search !== '') {
                $whereMovement[] = "(p.product_name LIKE :s OR p.barcode LIKE :s OR sm.created_by LIKE :s)";
                $paramsMovement[':s'] = '%' . $search . '%';
            }
            if ($date_from !== '') { $whereMovement[] = "DATE(sm.movement_date) >= :df"; $paramsMovement[':df'] = $date_from; }
            if ($date_to !== '') { $whereMovement[] = "DATE(sm.movement_date) <= :dt"; $paramsMovement[':dt'] = $date_to; }
            $whereMovementSql = count($whereMovement) ? ('WHERE ' . implode(' AND ', $whereMovement)) : '';

            $stmtMovement = $conn->prepare("
                SELECT
                    DATE(sm.movement_date) as date_created,
                    TIME(sm.movement_date) as time_created,
                    sm.created_by as username,
                    'Inventory' as role,
                    CONCAT('STOCK_', UPPER(sm.movement_type)) as action,
                    CONCAT(
                        CASE sm.movement_type
                            WHEN 'IN' THEN '📦 Stock Added: '
                            WHEN 'OUT' THEN '📤 Stock Removed: '
                            ELSE '📊 Stock Movement: '
                        END,
                        sm.quantity, ' units of ', p.product_name,
                        CASE WHEN sm.notes IS NOT NULL THEN CONCAT(' (', sm.notes, ')') ELSE '' END
                    ) as description
                FROM tbl_stock_movements sm
                LEFT JOIN tbl_product p ON sm.product_id = p.product_id
                $whereMovementSql
                ORDER BY sm.movement_date DESC
                LIMIT :limMovement
            ");
            foreach ($paramsMovement as $k => $v) { $stmtMovement->bindValue($k, $v); }
            $limMovement = min($limit, 100);
            $stmtMovement->bindValue(':limMovement', $limMovement, PDO::PARAM_INT);
            $stmtMovement->execute();
            $movementData = $stmtMovement->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            // Continue even if movement data fails
            error_log("Movement data fetch failed: " . $e->getMessage());
        }

        // Fetch inventory transfer history
        $transferData = [];
        try {
            $paramsTransfer = [];
            $whereTransfer = [];
            if ($search !== '') {
                $whereTransfer[] = "(th.transferred_by LIKE :s OR sl.location_name LIKE :s OR dl.location_name LIKE :s)";
                $paramsTransfer[':s'] = '%' . $search . '%';
            }
            if ($date_from !== '') { $whereTransfer[] = "DATE(th.date) >= :df"; $paramsTransfer[':df'] = $date_from; }
            if ($date_to !== '') { $whereTransfer[] = "DATE(th.date) <= :dt"; $paramsTransfer[':dt'] = $date_to; }
            $whereTransferSql = count($whereTransfer) ? ('WHERE ' . implode(' AND ', $whereTransfer)) : '';

            $stmtTransfer = $conn->prepare("
                SELECT
                    DATE(th.date) as date_created,
                    TIME(th.date) as time_created,
                    th.transferred_by as username,
                    'Inventory' as role,
                    'INVENTORY_TRANSFER' as action,
                    CONCAT(
                        '🚛 Transfer #', th.transfer_header_id, ': ',
                        sl.location_name, ' → ', dl.location_name,
                        ' (Status: ', UPPER(th.status), ')'
                    ) as description
                FROM tbl_transfer_header th
                LEFT JOIN tbl_location sl ON th.source_location_id = sl.location_id
                LEFT JOIN tbl_location dl ON th.destination_location_id = dl.location_id
                $whereTransferSql
                ORDER BY th.date DESC
                LIMIT :limTransfer
            ");
            foreach ($paramsTransfer as $k => $v) { $stmtTransfer->bindValue($k, $v); }
            $limTransfer = min($limit, 100);
            $stmtTransfer->bindValue(':limTransfer', $limTransfer, PDO::PARAM_INT);
            $stmtTransfer->execute();
            $transferData = $stmtTransfer->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            // Continue even if transfer data fails
            error_log("Transfer data fetch failed: " . $e->getMessage());
        }

        // Fetch login activity; materialize both login and logout as separate entries
        $paramsLogin = [];
        $whereLogin = [];
        if ($search !== '') {
            $whereLogin[] = "(l.username LIKE :s OR r.role_name LIKE :s OR e.Fname LIKE :s OR e.Lname LIKE :s)";
            $paramsLogin[':s'] = '%' . $search . '%';
        }
        if ($date_from !== '') { $whereLogin[] = "l.login_date >= :df"; $paramsLogin[':df'] = $date_from; }
        if ($date_to !== '') { $whereLogin[] = "l.login_date <= :dt"; $paramsLogin[':dt'] = $date_to; }
        $whereLoginSql = count($whereLogin) ? ('WHERE ' . implode(' AND ', $whereLogin)) : '';
        $stmtLogin = $conn->prepare("SELECT l.login_date, l.login_time, l.logout_date, l.logout_time, l.username, r.role_name AS role FROM tbl_login l LEFT JOIN tbl_role r ON l.role_id = r.role_id LEFT JOIN tbl_employee e ON l.emp_id = e.emp_id $whereLoginSql ORDER BY l.login_id DESC LIMIT :limLogin");
        foreach ($paramsLogin as $k => $v) { $stmtLogin->bindValue($k, $v); }
        $limLogin = min($limit, 500);
        $stmtLogin->bindValue(':limLogin', $limLogin, PDO::PARAM_INT);
        $stmtLogin->execute();
        $loginRows = $stmtLogin->fetchAll(PDO::FETCH_ASSOC);

        $logs = [];
        foreach ($loginRows as $lr) {
            // login record
            $logs[] = [
                'login_date' => $lr['login_date'],
                'login_time' => $lr['login_time'],
                'username' => $lr['username'],
                'role' => $lr['role'],
                'log_activity' => 'login',
                'description' => 'login'
            ];
            // logout record if present
            if (!empty($lr['logout_date']) && !empty($lr['logout_time'])) {
                $logs[] = [
                    'login_date' => $lr['logout_date'],
                    'login_time' => $lr['logout_time'],
                    'username' => $lr['username'],
                    'role' => $lr['role'],
                    'log_activity' => 'logout',
                    'description' => 'logout'
                ];
            }
        }

        // Normalize login logs to match the format of other logs
        foreach ($logs as &$log) {
            if (isset($log['login_date']) && !isset($log['date_created'])) {
                $log['date_created'] = $log['login_date'];
                $log['time_created'] = $log['login_time'];
                $log['action'] = $log['log_activity'];
                unset($log['login_date'], $log['login_time'], $log['log_activity']);
            }
        }

        // Merge all data sources: activity logs, movement data, transfer data, and login logs
        $allLogs = array_merge($activity, $movementData, $transferData, $logs);

        // Sort all logs by date/time descending
        usort($allLogs, function ($x, $y) {
            $dx = $x['date_created'] ?? '';
            $tx = $x['time_created'] ?? '';
            $dy = $y['date_created'] ?? '';
            $ty = $y['time_created'] ?? '';
            $sx = strtotime($dx . ' ' . $tx);
            $sy = strtotime($dy . ' ' . $ty);
            return $sy <=> $sx;
        });

        // Apply overall limit
        if (count($allLogs) > $limit) {
            $allLogs = array_slice($allLogs, 0, $limit);
        }

        echo json_encode(["success" => true, "data" => $allLogs]);
    } catch (Exception $e) {
        echo json_encode(["success" => false, "message" => "Error getting all logs: " . $e->getMessage(), "data" => []]);
    }
}
?>