<?php

namespace KoderZi\PhpGitHubUpdater;

use ZipArchive;

final class Updater
{
    const INIT = 100;
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
    private $status = self::ERROR;
    private $clear;
    private $maxLogs;
    private $archive_relative_paths;
    private $info = "";

    /**
     * Constructs a new instance of the updater class.
     *
     * @param string $username Your GitHub username.
     * @param string $repository The name of your GitHub repository.
     * @param string $token The generated GitHub personal access token for the repository.
     * @param string $version The current version number of your project.
     * @param string|null $admin (Optional) The email address of the admin who will receive an email in case of update failure.
     * @param string|null $mailer (Optional) The email address that the email will be sent from.
     * @param array|null $sourceExclusions (Optional) An array of directories or files in the source to be exclude from the update. The array keys:
     *      'path' => an array of source excluded paths
     *      'filename' => an array of source excluded filenames
     * @param array|null $releaseExclusions (Optional) An array of directories or files in the release to exclude from the update. The array keys:
     *      'path' => an array of release excluded paths
     *      'filename' => an array of release excluded filenames
     * @param bool $clear (Optional) Whether or not to clear the downloaded file. Defaults to true.
     * @param string $dir (Optional) The directory where the update will occur. Defaults to current working directory.
     * @param bool $autoUpdate (Optional) Whether or not to automatically update the project. Defaults to true.
     * @param int $maxLogs (Optional) Maximum number of log file to maintain. Defaults to 30.
     * @return void
     */
    public function __construct(string $username, string $repository, string $token, string $version, string|null $admin = '', string|null $mailer = '', array|null $sourceExclusions  = ['path' => [], 'filename' => []], array|null $releaseExclusions  = ['path' => [], 'filename' => []], bool $clear = true, string $dir = "", bool $autoUpdate = true, int $maxLogs = 30, string $info = "")
    {
        if ($admin == null) {
            $this->admin = '';
        } else {
            $this->admin = $admin;
        }

        if ($mailer == null) {
            $this->mailer = '';
        } else {
            $this->mailer = $mailer;
        }

        if ($sourceExclusions == null) {
            $sourceExclusions = [];
        }

        if (!isset($sourceExclusions['path'])) {
            $sourceExclusions['path'] = [];
        }

        if (!isset($sourceExclusions['filename'])) {
            $sourceExclusions['filename'] = [];
        }

        if ($releaseExclusions == null) {
            $releaseExclusions = [];
        }

        if (!isset($releaseExclusions['path'])) {
            $releaseExclusions['path'] = [];
        }
        if (!isset($releaseExclusions['filename'])) {
            $releaseExclusions['filename'] = [];
        }

        $this->username = $username;
        $this->repository = $repository;
        $this->token = $token;
        $this->version = $version;
        $this->exclude = ['source' => $sourceExclusions, 'release' =>  $releaseExclusions];
        $this->clear = $clear;

        if ($dir != "") {
            $this->dir = $dir;
        } else {
            $this->dir = getcwd();
        }

        $this->maxLogs = $maxLogs;
        $this->info = $info;

        $this->status = $this::INIT;

        if ($autoUpdate) {
            $this->update();
        }
        $this->Log();
    }

    /**
     * Retrieves the status of the updater.
     *
     * @return int One of the following status codes:
     *  - `INIT` (100): Indicates that update class has been initialized.
     *  - `UPDATED` (200): Indicates that the update was successful.
     *  - `LATEST` (204): Indicates that the project is already up to date.
     *  - `ERROR` (500): Indicates that the update failed.
     *  - `BUSY` (504): Indicates that an update process is already in progress.
     */
    public function status()
    {
        return $this->status;
    }

    /**
     * Retrieves the release version.
     *
     * @return string|false The release version if the information retrieved successfully, otherwise false.
     */
    public function release()
    {
        return $this->Download() ? $this->release : false;
    }

    /**
     * Executes update.
     *
     * @return void
     */
    public function update()
    {
        $update = $this->Install();

        if ($update == $this::ERROR) {
            if ($this->admin != '' && $this->mailer != '') {
                $this->Mail();
            }
        }
        $this->status = $update;
    }

    private function Log()
    {
        $logFiles = $this->MapPath($this->dir . "/update/log", ['filename' => ['.htaccess']]);
        $logFiles = array_reverse($logFiles);
        if (count($logFiles) >= $this->maxLogs) {
            $this->log[] = [date("Y-m-d H:i:s"), "Deleting excess log files"];
            while (count($logFiles) >= $this->maxLogs) {
                $logFileToRemove = array_pop($logFiles);
                $this->log[] = [date("Y-m-d H:i:s"), "Deleting log file: $logFileToRemove"];
                if (file_exists($logFileToRemove)) {
                    unlink($logFileToRemove);
                }
            }
        } else {
            $this->log[] = [date("Y-m-d H:i:s"), "No excess log files to delete"];
        }
        $this->log[] = [date("Y-m-d H:i:s"), "Saving log file"];
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
                    <p>Update Info:</p>
                    <table>
                        <tr>
                            <td>Username</td>
                            <td>' . $this->username . '</td>
                        </tr>
                        <tr>
                            <td>Repository</td>
                            <td>' . $this->repository . '</td>
                        </tr>
                        <tr>
                            <td>Plugin Version</td>
                            <td>' . $this->version . '</td>
                        </tr>
                        <tr>
                            <td>Additional Info</td>
                            <td>' . $this->info . '</td> 
                        </tr>
                    </table>
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
            $this->status = $this::ERROR;
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

    private function CreateFile(string $FileName, string $FilePath = '', string $Content = '')
    {
        if ($FilePath == '') {
            $download_path = $this->dir . '/' . $FileName;
        } else {
            $download_path = $this->dir . '/' . trim($FilePath, '/') . '/' . $FileName;
        }
        if (file_put_contents($download_path, $Content) !== false) {
            $this->log[] = [date("Y-m-d H:i:s"), "$FileName file created. $download_path"];
            return true;
        } else {
            $this->log[] = [date("Y-m-d H:i:s"), "$FileName file cannot be created. $download_path"];
            return false;
        }
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
            $download_file = $this->dir . "/update/" . $this->repository . ".zip";
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
        $download_file = $this->dir . "/update/" . $this->repository . ".zip";
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
                rename(glob($extract_path . '/*')[0], $extract_path . '/' . $this->repository);
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

        $source_exclude = [];
        $source_exclude['path'] = [$this->dir . '/.git', $this->dir . '/update', $this->dir . '/update.lock', $this->dir . '/vendor'];
        $source_exclude['path'] = array_merge($source_exclude['path'], $this->exclude['source']['path']);
        $source_exclude['path'] = array_unique($source_exclude['path']);

        $source_exclude['filename'] = [];
        $source_exclude['filename'] = array_merge($source_exclude['filename'], $this->exclude['source']['filename']);
        $source_exclude['filename'] = array_unique($source_exclude['filename']);

        $this->log[] = [date("Y-m-d H:i:s"), "App exclude:\n" . json_encode($source_exclude, JSON_PRETTY_PRINT)];

        $source_paths = $this->MapPath($this->dir, $source_exclude);
        $this->log[] = [date("Y-m-d H:i:s"), "App lists:\n" . json_encode($source_paths, JSON_PRETTY_PRINT)];

        $source_relative_paths = array_map(function ($source_path) {
            return substr_replace($source_path, '', 0, strlen($this->dir));
        }, $source_paths);

        $release_exclude = [];
        $release_exclude['path'] = $this->exclude['release']['path'];

        $release_exclude['filename'] = ['composer.phar', '.gitignore', '.gitkeep'];
        $release_exclude['filename'] = array_merge($release_exclude['filename'], $this->exclude['release']['filename']);
        $release_exclude['filename'] = array_unique($release_exclude['filename']);
        $this->log[] = [date("Y-m-d H:i:s"), "Upgrade exclude:\n" . json_encode($release_exclude, JSON_PRETTY_PRINT)];

        $release_paths = $this->MapPath($this->dir . "/update/extract/{$this->repository}", $release_exclude);
        $this->log[] = [date("Y-m-d H:i:s"), "Upgrade lists:\n" . json_encode($release_paths, JSON_PRETTY_PRINT)];

        $release_relative_paths = array_map(function ($release_path) {
            return substr_replace($release_path, '', 0, strlen($this->dir . "/update/extract/{$this->repository}"));
        }, $release_paths);

        $this->archive_relative_paths = $release_relative_paths;

        foreach ($release_relative_paths as $release_relative_path) {
            $release_path = $this->dir . "/update/extract/{$this->repository}$release_relative_path";
            $source_path = $this->dir . "$release_relative_path";
            if (is_dir($release_path)) {
                if (!is_dir($source_path)) {
                    if (mkdir($source_path, 0755, true)) {
                        $this->log[] = [date("Y-m-d H:i:s"), "Folder created. $source_path"];
                    } else {
                        $this->log[] = [date("Y-m-d H:i:s"), "Folder cannot be created. $source_path"];
                        return false;
                    }
                }
            } elseif (is_file($release_path)) {
                if (is_file($source_path)) {
                    $release_content = file_get_contents($release_path, true);
                    if ($release_content === false) {
                        $this->log[] = [date("Y-m-d H:i:s"), "Failed to retrieve update content. $release_path"];
                        return false;
                    };
                    $content_size = file_put_contents($source_path, $release_content);
                    if ($content_size === false) {
                        $this->log[] = [date("Y-m-d H:i:s"), "Failed to write update content. $source_path"];
                        return false;
                    };
                    $this->log[] = [date("Y-m-d H:i:s"), "$content_size bytes written from $release_path to $source_path"];
                } else {
                    if (!copy($release_path, $source_path)) {
                        $this->log[] = [date("Y-m-d H:i:s"), "Failed to copy update content. $source_path"];
                        return false;
                    };
                }
            }
        }

        $delete_relative_paths = array_values(array_diff($source_relative_paths, $release_relative_paths));

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
        $cleaned = true;
        if (file_exists($this->dir . "/update/" . $this->repository . ".zip") && !$this->Delete($this->dir . "/update/" . $this->repository . ".zip")) {
            $this->log[] = [date("Y-m-d H:i:s"), "Failed to delete downloaded zip file. " . $this->dir . "/update/" . $this->repository . ".zip"];
            $cleaned = false;
        };
        if (!$this->clear && $this->status !== $this::ERROR) {
            $archived = true;
            $zip = new ZipArchive;
            $zip_file = $this->dir . "/update/{$this->repository}.zip";
            if ($zip->open($zip_file, ZipArchive::CREATE) !== true) {
                $this->log[] = [date("Y-m-d H:i:s"), "Failed to create archive file. $zip_file"];
                $cleaned = false;
                $archived = false;
            }
            if ($zip->addEmptyDir($this->repository) === false) {
                $this->log[] = [date("Y-m-d H:i:s"), "Failed to create directory in archive file. {$this->repository}"];
                $cleaned = false;
                $archived = false;
            }
            foreach ($this->archive_relative_paths as $archive_relative_path) {
                $release_path = $this->dir . "/update/extract/{$this->repository}$archive_relative_path";
                if (is_dir($release_path)) {
                    if ($zip->addEmptyDir($this->repository . $archive_relative_path) === false) {
                        $this->log[] = [date("Y-m-d H:i:s"), "Failed to create directory in archive file. " . $this->repository . $archive_relative_path];
                        $cleaned = false;
                        $archived = false;
                    }
                } elseif (is_file($release_path)) {
                    if ($zip->addFile($release_path, $this->repository . $archive_relative_path) === false) {
                        $this->log[] = [date("Y-m-d H:i:s"), "Failed to add file to archive file. " . $this->repository . $archive_relative_path];
                        $cleaned = false;
                        $archived = false;
                    };
                }
            }
            $zip->close();
            if (!$archived) {
                if (file_exists($zip_file) && !$this->Delete($zip_file)) {
                    $this->log[] = [date("Y-m-d H:i:s"), "Failed to delete archive file. $zip_file"];
                    $cleaned = false;
                };
            }
        }
        if (file_exists($this->dir . "/update/extract") && !$this->Delete($this->dir . "/update/extract")) {
            $this->log[] = [date("Y-m-d H:i:s"), "Failed to delete extracted folder. " . $this->dir . "/update/extract"];
            $cleaned = false;
        };
        if ($cleaned) {
            $this->log[] = [date("Y-m-d H:i:s"), "Cleanup completed."];
        } else {
            $this->log[] = [date("Y-m-d H:i:s"), "Cleanup completed with errors."];
        }
        return $cleaned;
    }

    private function Install()
    {
        $this->log[] = [date("Y-m-d H:i:s"), "Update started."];
        if (!$this->Lock()) {
            if (file_exists($this->dir . "/update.lock")) {
                $this->log[] = [date("Y-m-d H:i:s"), "Update already running. Update terminated."];
                $this->status = $this::BUSY;
                return $this->status;
            }
            $this->log[] = [date("Y-m-d H:i:s"), "Update lock aquiring failed. Update terminated."];
            $this->status = $this::ERROR;
            $this->Unlock();
            return $this->status;
        }
        if (!$this->Folder()) {
            $this->log[] = [date("Y-m-d H:i:s"), "Folder creation process failed. Update terminated."];
            $this->status = $this::ERROR;
            $this->Unlock();
            return $this->status;
        }
        $htaccess_path = $this->dir . '/update/log/.htaccess';
        if (!file_exists($htaccess_path) || file_get_contents($htaccess_path) !== "Order Deny,Allow\nDeny from all") {
            if (!$this->CreateFile('.htaccess', 'update/log', "Order Deny,Allow\nDeny from all")) {
                $this->log[] = [date("Y-m-d H:i:s"), ".htaccess creation process failed. Update terminated."];
                $this->status = $this::ERROR;
                $this->Unlock();
                return $this->status;
            }
        }
        if (!$this->Version()) {
            $this->log[] = [date("Y-m-d H:i:s"), "Version check process failed. Update terminated."];
            $this->status = $this::ERROR;
            $this->Unlock();
            return $this->status;
        }
        if (!version_compare($this->release, $this->version, '>')) {
            $this->log[] = [date("Y-m-d H:i:s"), "Version already up to date (Release {$this->release}). Update terminated."];
            $this->status = $this::LATEST;
            $this->Unlock();
            return $this->status;
        }
        $download_try = 0;
        while (!$this->Download($this->zip_url)) {
            $download_try++;
            if ($download_try > 3) {
                $this->log[] = [date("Y-m-d H:i:s"), "Download process failed. Update terminated."];
                $this->status = $this::ERROR;
                $this->Unlock();
                return $this->status;
            }
            $this->log[] = [date("Y-m-d H:i:s"), "Unable to retrieve download. Retry in 5 seconds."];
            sleep(5);
        }
        if (!$this->Extract()) {
            $this->log[] = [date("Y-m-d H:i:s"), "Extraction process failed. Update terminated."];
            $this->status = $this::ERROR;
            $this->Unlock();
            return $this->status;
        }
        if (!$this->Upgrade()) {
            $this->log[] = [date("Y-m-d H:i:s"), "Upgrade process failed. Update terminated."];
            $this->status = $this::ERROR;
            $this->Unlock();
            return $this->status;
        }
        $this->log[] = [date("Y-m-d H:i:s"), "Update completed."];
        $this->status = $this::UPDATED;
        $this->Unlock();
        return $this->status;
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
