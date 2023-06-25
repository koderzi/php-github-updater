<?php

namespace KoderZi\PhpGitHubUpdater;

use ZipArchive;

final class Updater
{
    const STARTED = 100;
    const UPDATED = 200;
    const LATEST = 204;
    const ERROR = 500;
    const BUSY = 504;

    private $username;
    private $repository;
    private $token;
    private $version;
    private $release;
    private $zip_url;
    private $admin;
    private $mailer;
    private $dir;
    private $exclude = [];
    private $log = [];
    private $status;

    /**
     * Constructs a new instance of the class and starts the update process for the provided version.
     *
     * @param string $username Your GitHub username.
     * @param string $repository The name of your GitHub repository.
     * @param string $token The generated GitHub personal access token for the repository.
     * @param string $version The current version number of your project.
     * @param string|null $admin (Optional) The email address of the admin who will receive an email in case of update failure.
     * @param string|null $mailer (Optional) The email address that the email will be sent from.
     * @param array|null $exclude (Optional) An array of directories or files to exclude from the update. The array keys:
     *      'path' => an array of excluded paths
     *      'filename' => an array of excluded filenames
     * @return void
     */
    public function __construct(string $username, string $repository, string $token, string $version, string|null $admin = '', string|null $mailer = '', array|null $exclude = ['path' => [], 'filename' => []])
    {
        $this->status = $this::STARTED;
        $this->username = $username;
        $this->repository = $repository;
        $this->token = $token;
        $this->version = $version;
        $this->admin = $admin;
        $this->mailer = $mailer;
        $this->dir = getcwd();

        if (!isset($exclude['path'])) {
            $exclude['path'] = [];
        }
        if (!isset($exclude['filename'])) {
            $exclude['filename'] = [];
        }

        $this->exclude = $exclude;

        $update = $this->Install();

        if ($update == $this::ERROR) {
            if ($this->admin != '' && $this->mailer != '') {
                $this->Mail();
            }
        }
        $this->Log();
        if ($update == $this::UPDATED) {
            if (class_exists('Composer\Autoload\ClassLoader')) {
                if (is_dir(exec('which composer'))) {
                    exec('composer install -d ' . getcwd());
                } elseif (file_exists(getcwd() . '/composer.phar')) {
                    exec('php composer.phar install -d ' . getcwd());
                } elseif (file_exists(($composer_path = exec('find / -name "composer.phar" 2>/dev/null')))) {
                    exec('php ' . $composer_path . ' install -d ' . getcwd());
                }
            }
        }
        $this->status = $update;
    }

    /**
     * Retrieves the status of the updater.
     *
     * @return int One of the following status codes:
     *  - `STARTED` (100): Indicates that the update has started.
     *  - `UPDATED` (200): Indicates that the update was successful.
     *  - `LATEST` (204): Indicates that the project is already up to date.
     *  - `ERROR` (500): Indicates that the update failed.
     *  - `BUSY` (504): Indicates that an update process is already in progress.
     */
    public function status()
    {
        return $this->status;
    }

    private function Log()
    {
        $log = implode("\n", array_map(function ($entry) {
            return "{$entry[0]}: {$entry[1]}";
        }, $this->log));
        file_put_contents($this->dir . "/update/log/" . date("Y-m-d H:i:s") . ".txt", $log);
    }

    private function Mail()
    {
        $subject = "Github Updater Failed: {$this->repository} - Action Required";
        $headers = "From: <{$this->mailer}>\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $html_message =
            '<!DOCTYPE html>
            <html>
                <head>
                    <meta charset="utf-8">
                    <title>Github Updater Failed: {{ REPOSITORY }} - Action Required</title>
                    <style>
                        table {
                            border-collapse: collapse;
                        }
                        tr {
                            width: 100%;
                        }
                        th, td {
                            border: 1px solid black;
                            padding: 5px;
                        }
                    </style>
                </head>
                <body>
                    <p>Dear Admin,</p>
                    <p>We regret to inform you that the latest update for the {{ REPOSITORY }} repository has failed. Please take appropriate action to resolve the issue.</p>
                    <p>Update Logs:</p>
                    <table>
                        ' . implode("", array_map(function ($entry) {
                return "<tr><td>{$entry[0]}</td><td>{$entry[1]}</td></tr>";
            }, $this->log)) . '
                    </table>
                    <p>Thank you for your attention to this matter.</p>
                    <p>Best regards</p>
                </body>
            </html>';
        $html_message = str_replace("{{ REPOSITORY }}", $this->repository, $html_message);
        if (mail($this->admin, $subject, $html_message, $headers)) {
            $this->log[] = [date("Y-m-d H:i:s"), "Email sent to {$this->admin}"];
        } else {
            $this->log[] = [date("Y-m-d H:i:s"), "Failed to send mail to {$this->admin}"];
        }
    }

    private function Lock()
    {
        if (!$this->CreateFolder('update')) {
            $this->log[]    = 'created update';
            return false;
        }
        if (!$this->CreateFolder('log', '/update')) {
            $this->log[]    = 'created log';
            return false;
        }
        for ($i = 0; $i < 3; $i++) {
            if (!file_exists($this->dir . '/update.lock') && file_put_contents($this->dir . '/update.lock', '') !== false) {
                $this->log[] = [date("Y-m-d H:i:s"), "Update lock acquired."];
                return true;
            }
            $this->log[] = [date("Y-m-d H:i:s"), "Failed to acquire update lock. Retry in 10 seconds."];
            sleep(10);
        }
        return false;
    }

    private function Unlock()
    {
        if (!$this->CleanUp()) {
            $this->log[] = [date("Y-m-d H:i:s"), "Cleanup process failed."];
        }
        $this->log[] = [date("Y-m-d H:i:s"), "Releasing update lock."];
        if (!file_exists($this->dir . '/update.lock')) {
            $this->log[] = [date("Y-m-d H:i:s"), "Update lock unavailable."];
        }
        if ($this->Delete($this->dir . '/update.lock')) {
            $this->log[] = [date("Y-m-d H:i:s"), "Update lock released."];
        }
        return;
    }

    private function Folder()
    {
        if (!$this->CreateFolder('update')) {
            return false;
        }
        if (!$this->CreateFolder('log', '/update')) {
            return false;
        }
        if (!$this->CreateFolder('extract', '/update')) {
            return false;
        }
        return true;
    }

    private function CreateFolder(string $FolderName, string $FolderPath = '')
    {
        if ($FolderPath == '') {
            $download_path = $this->dir . '/' . $FolderName;
        } else {
            $download_path = $this->dir . '/' . trim($FolderPath, '/') . '/' . $FolderName;
        }
        if (true !== is_dir($download_path)) {
            $FolderName = ucfirst($FolderName);
            if (mkdir($download_path, 0700, true)) {
                $this->log[] = [date("Y-m-d H:i:s"), "$FolderName folder created. $download_path"];
            } else {
                $this->log[] = [date("Y-m-d H:i:s"), "$FolderName folder cannot be created. $download_path"];
                return false;
            }
        }
        return true;
    }

    private function Version()
    {
        if ($this->Download()) {
            return true;
        } else {
            return false;
        }
    }

    private function Download($url = null)
    {
        date_default_timezone_set('Asia/Kuala_Lumpur');
        $headers = [
            "Accept: application/vnd.github+json",
            "Authorization: Bearer $this->token",
            "X-GitHub-Api-Version: 2022-11-28"
        ];
        $useragent = 'Mozilla/5.0 (Windows NT 6.2; WOW64; rv:17.0) Gecko/20100101 Firefox/17.0';
        if (($ch = curl_init()) == false) {
            $this->log[] = [date("Y-m-d H:i:s"), "Curl initialization failed."];
            return false;
        };
        if ((curl_setopt($ch, CURLOPT_HTTPHEADER, $headers)) == false) {
            $this->log[] = [date("Y-m-d H:i:s"), "Curl headers initialization failed"];
            return false;
        };
        if ((curl_setopt($ch, CURLOPT_USERAGENT, $useragent)) == false) {
            $this->log[] = [date("Y-m-d H:i:s"), "Curl user agent initialization failed. $useragent"];
            return false;
        };
        if (is_null($url)) {
            $curl_url = "https://api.github.com/repos/{$this->username}/{$this->repository}/releases/latest";
            if ((curl_setopt($ch, CURLOPT_URL, $curl_url)) == false) {
                $this->log[] = [date("Y-m-d H:i:s"), "Curl URL initialization failed. $curl_url"];
                return false;
            };
            if ((curl_setopt($ch, CURLOPT_RETURNTRANSFER, true)) == false) {
                $this->log[] = [date("Y-m-d H:i:s"), "Curl return type initialization failed."];
                return false;
            };
            if (($exec = curl_exec($ch)) == false) {
                $this->log[] = [date("Y-m-d H:i:s"), "Curl execution failed."];
                return false;
            }
            if (($latest = json_decode($exec, true)) == false) {
                $this->log[] = [date("Y-m-d H:i:s"), "Curl json_decode failed."];
                return false;
            };
            curl_close($ch);
            if (isset($latest['tag_name']) && isset($latest['zipball_url'])) {
                $this->release = $latest['tag_name'];
                $this->zip_url = $latest['zipball_url'];
                $this->log[] = [date("Y-m-d H:i:s"), "Git data retrievied:\n" . json_encode($latest, JSON_PRETTY_PRINT)];
                return true;
            } else {
                $this->log[] = [date("Y-m-d H:i:s"), 'Failed to retrieve Git data.'];
                return false;
            }
        } else {
            $download_file = $this->dir . "/update/update.zip";
            if (file_exists($download_file)) {
                $this->log[] = [date("Y-m-d H:i:s"), "Deleting existing zip file. $download_file"];
                if (!$this->Delete($download_file)) {
                    $this->log[] = [date("Y-m-d H:i:s"), "Failed to delete existing zip file. $download_file"];
                    return false;
                };
            }
            if ((curl_setopt($ch, CURLOPT_URL, $url)) == false) {
                $this->log[] = [date("Y-m-d H:i:s"), "Curl URL initialization failed. $url"];
                return false;
            };
            if (($fp = fopen($download_file, "w")) == false) {
                $this->log[] = [date("Y-m-d H:i:s"), "Failed to create download file. $download_file"];
                return false;
            };
            if ((curl_setopt($ch, CURLOPT_FILE, $fp)) == false) {
                $this->log[] = [date("Y-m-d H:i:s"), "Curl file initialization failed."];
                return false;
            };
            if ((curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true)) == false) {
                $this->log[] = [date("Y-m-d H:i:s"), "Curl follow location initialization failed."];
                return false;
            };
            if (($exec = curl_exec($ch)) == false) {
                $this->log[] = [date("Y-m-d H:i:s"), "Curl execution failed."];
                return false;
            }
            curl_close($ch);
            fclose($fp);
            if (($status = curl_getinfo($ch, CURLINFO_HTTP_CODE)) != 200) {
                $this->log[] = [date("Y-m-d H:i:s"), "Curl return $status."];
                return false;
            };
            if (!file_exists($download_file)) {
                $this->log[] = [date("Y-m-d H:i:s"), "Failed to download zip file. $download_file"];
                return false;
            }
            $this->log[] = [date("Y-m-d H:i:s"), "Zip file downloaded. $download_file"];
            return true;
        }
    }

    private function Extract()
    {
        $download_file = $this->dir . "/update/update.zip";
        $extract_path = $this->dir . "/update/extract";
        if (file_exists($extract_path)) {
            $this->log[] = [date("Y-m-d H:i:s"), "Deleting existing extract folder. $extract_path"];
            if (!$this->Delete($extract_path)) {
                $this->log[] = [date("Y-m-d H:i:s"), "Failed to delete existing extract folder. $extract_path"];
                return false;
            };
        }
        $zip = new ZipArchive;
        $res = $zip->open($download_file);
        if ($res === true) {
            if ($zip->extractTo($extract_path)) {
                $this->log[] = [date("Y-m-d H:i:s"), "Extraction completed. $extract_path"];
                $zip->close();
                rename(glob($extract_path . '/*')[0], $extract_path . '/tmp_' . $this->repository);
                return true;
            } else {
                $this->log[] = [date("Y-m-d H:i:s"), "Extraction failed. $extract_path"];
                $zip->close();
                return false;
            }
        } else {
            $this->log[] = [date("Y-m-d H:i:s"), "Zip file is corrupt. $download_file"];
            return false;
        }
    }

    private function Upgrade()
    {
        sleep(10);

        $app_exclude = [];
        $app_exclude['path'] = [$this->dir . '/.git', $this->dir . '/update', $this->dir . '/update.lock', $this->dir . '/vendor', $this->dir . '/composer.phar'];
        $app_exclude['path'] = array_merge($app_exclude['path'], $this->exclude['path']);
        $app_exclude['path'] = array_unique($app_exclude['path']);

        $app_exclude['filename'] = ['.gitignore'];
        $app_exclude['filename'] = array_merge($app_exclude['filename'], $this->exclude['filename']);
        $app_exclude['filename'] = array_unique($app_exclude['filename']);

        $this->log[] = [date("Y-m-d H:i:s"), "App exclude:\n" . json_encode($app_exclude, JSON_PRETTY_PRINT)];

        $app_paths = $this->MapPath($this->dir, $app_exclude);
        $this->log[] = [date("Y-m-d H:i:s"), "App lists:\n" . json_encode($app_paths, JSON_PRETTY_PRINT)];

        $app_relative_paths = array_map(function ($app_path) {
            return substr_replace($app_path, '', 0, strlen($this->dir));
        }, $app_paths);

        $upgrade_exclude = [];
        $upgrade_exclude['path'] = $this->exclude['path'];

        $upgrade_exclude['filename'] = ['.gitignore'];
        $upgrade_exclude['filename'] = array_merge($upgrade_exclude['filename'], $this->exclude['filename']);
        $upgrade_exclude['filename'] = array_unique($upgrade_exclude['filename']);
        $this->log[] = [date("Y-m-d H:i:s"), "Upgrade exclude:\n" . json_encode($upgrade_exclude, JSON_PRETTY_PRINT)];

        $upgrade_paths = $this->MapPath($this->dir . "/update/extract/tmp_{$this->repository}", $upgrade_exclude);
        $this->log[] = [date("Y-m-d H:i:s"), "Upgrade lists:\n" . json_encode($upgrade_paths, JSON_PRETTY_PRINT)];

        $upgrade_relative_paths = array_map(function ($upgrade_path) {
            return substr_replace($upgrade_path, '', 0, strlen($this->dir . "/update/extract/tmp_{$this->repository}"));
        }, $upgrade_paths);

        foreach ($upgrade_relative_paths as $upgrade_relative_path) {
            $upgrade_path = $this->dir . "/update/extract/tmp_{$this->repository}$upgrade_relative_path";
            $app_path = $this->dir . "$upgrade_relative_path";
            if (is_dir($upgrade_path)) {
                if (!is_dir($app_path)) {
                    if (mkdir($app_path, 0700, true)) {
                        $this->log[] = [date("Y-m-d H:i:s"), "Folder created. $app_path"];
                    } else {
                        $this->log[] = [date("Y-m-d H:i:s"), "Folder cannot be created. $app_path"];
                        return false;
                    }
                }
            } elseif (is_file($upgrade_path)) {
                if (is_file($app_path)) {
                    $upgrade_content = file_get_contents($upgrade_path, true);
                    if ($upgrade_content === false) {
                        $this->log[] = [date("Y-m-d H:i:s"), "Failed to retrieve update content. $upgrade_path"];
                        return false;
                    };
                    $content_size = file_put_contents($app_path, $upgrade_content);
                    if ($content_size === false) {
                        $this->log[] = [date("Y-m-d H:i:s"), "Failed to write update content. $app_path"];
                        return false;
                    };
                    $this->log[] = [date("Y-m-d H:i:s"), "$content_size bytes written from $upgrade_path to $app_path"];
                } else {
                    if (!copy($upgrade_path, $app_path)) {
                        $this->log[] = [date("Y-m-d H:i:s"), "Failed to copy update content. $app_path"];
                        return false;
                    };
                }
            }
        }

        $delete_relative_paths = array_values(array_diff($app_relative_paths, $upgrade_relative_paths));

        foreach ($delete_relative_paths as $delete_relative_path) {
            if (is_dir($this->dir . $delete_relative_path)) {
                foreach ($delete_relative_paths as $_delete_relative_key => $_delete_relative_path) {
                    if ($delete_relative_path != $_delete_relative_path && substr($_delete_relative_path, 0, strlen($delete_relative_path)) == $delete_relative_path) {
                        unset($delete_relative_paths[$_delete_relative_key]);
                    }
                }
            }
        }

        $delete_paths = array_values(array_map(function ($delete_relative_path) {
            return $this->dir . $delete_relative_path;
        }, $delete_relative_paths));
        $this->log[] = [date("Y-m-d H:i:s"), "Delete lists:\n" . json_encode($delete_paths, JSON_PRETTY_PRINT)];

        foreach ($delete_paths as $delete_path) {
            if (!$this->Delete($delete_path)) {
                $this->log[] = [date("Y-m-d H:i:s"), "Failed to delete $delete_path"];
                return false;
            } else {
                $this->log[] = [date("Y-m-d H:i:s"), "Deleted $delete_path"];
            }
        }
        return true;
    }

    private function CleanUp()
    {
        if (file_exists($this->dir . "/update/extract") && !$this->Delete($this->dir . "/update/extract")) {
            $this->log[] = [date("Y-m-d H:i:s"), "Cleanup failed. " . $this->dir . "/update/extract"];
            return false;
        };
        if (file_exists($this->dir . "/update/update.zip") && !$this->Delete($this->dir . "/update/update.zip")) {
            $this->log[] = [date("Y-m-d H:i:s"), "Cleanup failed. " . $this->dir . "/update/update.zip"];
            return false;
        };
        $this->log[] = [date("Y-m-d H:i:s"), "Cleanup completed."];
        return true;
    }

    private function Install()
    {
        $this->log[] = [date("Y-m-d H:i:s"), "Update started."];
        if (!$this->Lock()) {
            if (file_exists($this->dir . "/update.lock")) {
                $this->log[] = [date("Y-m-d H:i:s"), "Update already running. Update terminated."];
                return $this::BUSY;
            }
            $this->log[] = [date("Y-m-d H:i:s"), "Update lock aquiring failed. Update terminated."];
            $this->Unlock();
            return $this::ERROR;
        }
        if (!$this->Folder()) {
            $this->log[] = [date("Y-m-d H:i:s"), "Folder creation process failed. Update terminated."];
            $this->Unlock();
            return $this::ERROR;
        }
        if (!$this->Version()) {
            $this->log[] = [date("Y-m-d H:i:s"), "Version check process failed. Update terminated."];
            $this->Unlock();
            return $this::ERROR;
        }
        if (!version_compare($this->release, $this->version, '>')) {
            $this->log[] = [date("Y-m-d H:i:s"), "Version already up to date (Release {$this->release}). Update terminated."];
            $this->Unlock();
            return $this::LATEST;
        }
        $download_try = 0;
        while (!$this->Download($this->zip_url)) {
            $download_try++;
            if ($download_try > 3) {
                $this->log[] = [date("Y-m-d H:i:s"), "Download process failed. Update terminated."];
                $this->Unlock();
                return $this::ERROR;
            }
            $this->log[] = [date("Y-m-d H:i:s"), "Unable to retrieve download. Retry in 5 seconds."];
            sleep(5);
        }
        if (!$this->Extract()) {
            $this->log[] = [date("Y-m-d H:i:s"), "Extraction process failed. Update terminated."];
            $this->Unlock();
            return $this::ERROR;
        }
        if (!$this->Upgrade()) {
            $this->log[] = [date("Y-m-d H:i:s"), "Upgrade process failed. Update terminated."];
            $this->Unlock();
            return $this::ERROR;
        }
        $this->log[] = [date("Y-m-d H:i:s"), "Update completed."];
        $this->Unlock();
        return $this::UPDATED;
    }

    private function MapPath($path, $exclude = ['path' => [], 'filename' => []], &$list = [])
    {
        if (!isset($exclude['path'])) {
            $exclude['path'] = [];
        }
        if (!isset($exclude['filename'])) {
            $exclude['filename'] = [];
        }
        if (true === is_dir($path)) {
            $scan_paths = array_values(array_diff(scandir($path), ['.', '..']));
            $scan_paths = array_map(function ($scan_path) use ($path) {
                $scan_path = $path . '/' . $scan_path;
                return $scan_path;
            }, $scan_paths);
            if (count($exclude['path']) > 0) {
                $scan_paths = array_map(function ($scan_path) use ($path) {
                    if (substr($scan_path, -1) !== '/') {
                        $scan_path .= '/';
                    }
                    return $scan_path;
                }, $scan_paths);
                $exclude_paths = array_map(function ($exclude_path) {
                    if (substr($exclude_path, -1) !== '/') {
                        $exclude_path .= '/';
                    }
                    return $exclude_path;
                }, $exclude['path']);
                $filtered_paths = array_filter($scan_paths, function ($scan_path) use ($exclude_paths) {
                    foreach ($exclude_paths as $exclude_path) {
                        if (strpos($scan_path, $exclude_path) === 0) {
                            return false;
                        }
                    }
                    return true;
                });
                $filtered_paths = array_values(array_map(function ($filtered_path) {
                    return rtrim($filtered_path, '/');
                }, $filtered_paths));
            } else {
                $filtered_paths = $scan_paths;
            }
            if (count($filtered_paths) > 0) {
                foreach ($filtered_paths as $filtered_key => $filtered_path) {
                    if (true === is_dir($filtered_path)) {
                        $list[] = $filtered_path;
                    }
                    $this->MapPath(realpath($filtered_path), $exclude, $list);
                }
            }
            return $list;
        } elseif (true === is_file($path)) {
            if (count($exclude['filename']) > 0) {
                $filename = basename(realpath($path));
                if (!in_array($filename, $exclude['filename'])) {
                    $list[] = $path;
                }
            } else {
                $list[] = $path;
            }
            return $list;
        }
        return $list;
    }

    private function Delete($path)
    {
        if (true === is_dir($path)) {
            $scan_paths = array_values(array_diff(scandir($path), ['.', '..']));
            foreach ($scan_paths as $scan_path) {
                $this->Delete(realpath($path) . '/' . $scan_path);
            }
            return rmdir($path);
        } elseif (true === is_file($path)) {
            return unlink($path);
        }
        return false;
    }
}
