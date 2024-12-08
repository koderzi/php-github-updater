# GitHub Release Updater

The GitHub Release Updater is a PHP class that enables you to update your project from the latest GitHub repository releases. The class is simple to use and highly efficient in keeping your project up-to-date with the latest releases.

## Preparation

Before using this class, you need to generate a personal access token on GitHub. Follow these steps:

- Go to https://github.com/settings/tokens/new
- Select the repository and enable read-only access for Contents and Metadata.
- Click on "Generate token" and copy the token.

## Installation

To use this class with Composer, follow these steps:

- Open a terminal or command prompt in your project's root directory.
- Run the following command to initialize a composer.json file in your project:

```
    composer init
```

- Follow the prompts to fill out the details for your project (e.g. package name, description, author, etc.).
- Run the following command to install the koderzi/php-github-updater package:

```
    composer require koderzi/php-github-updater
```

This will download the package and its dependencies and add them to your vendor directory.

- Include the vendor/autoload.php file in your project's to autoload the class provided by the package:

```
    require_once "vendor/autoload.php";
```

If you're using a framework or other autoloading mechanism, you may need to include this file manually.

To use this class with direct download, follow these steps:

- Retrieve the Updater.php file from the src directory in the repository.
- Put the Updater.php file in your project's directory.
- include the file to your project's to load the class.

## Usage

To initialize the Updater class and start the update process, follow these steps:

- Instantiate the class with the following parameters:

```
    use KoderZi\PhpGitHubUpdater\Updater;

    $update = new Updater(
        string $username,
        string $repository,
        string $token,
        string $version,
        string|null $admin,
        string|null $mailer,
        array|null $sourceExclusions = ['path' => [], 'filename' => []],
        array|null $releaseExclusions  = ['path' => [], 'filename' => []],
        bool $clear = true,
        string $dir = ""
        bool $autoUpdate = true
    );
```

> `$username` Your GitHub username.<br>
> `$repository` The name of your GitHub repository.<br>
> `$token` The personal access token you generated earlier.<br>
> `$version` The current version number of your project.<br>
> `$admin` (Optional) The email address of the admin who will receive an email in case of update failure.<br>
> `$mailer` (Optional) The email address that the email will be sent from.<br>
> `$sourceExclusions` (Optional)  An array of directories or files in the source to be exclude from the update.<br>
> `$releaseExclusions` (Optional) An array of directories or files in the release to exclude from the update.<br>
> `$clear` (Optional) Clear the downloaded file after the update has completed if set to true.<br>
> `$dir` (Optional) Set the directory of the update. Default to current working dir.<br>
> `$autoUpdate` (Optional) Whether or not to automatically update the project. Defaults to true.<br>
> `$maxLogs` (Optional) Maximum number of log file to maintain. Defaults to 30.

> The exclusions array keys:

```
    $sourceExclusions = [
        'path' => an array of source excluded paths,
        'filename' => an array of source excluded filenames
    ]

    $releaseExclusions = [
        'path' => an array of release excluded paths,
        'filename' => an array of release excluded filenames
    ]
```

To check the release version, use the following code:

```
    $update->release();
```

If a new release is available, the class will update your project automatically.
To update manually, set $autoUpdate to false and use the following code to start update:

```
    $update->update();
```

To check the status of the update, use the following code:

```
    $update->status();
```

The update status can have the following int values:

> `Updater::INIT` (100): Indicates that update class has been initialized.<br>
> `Updater::UPDATED` (200): Indicates that the update was successful.<br>
> `Updater::LATEST` (204): Indicates that the project is already up to date.<br>
> `Updater::ERROR` (500): Indicates that the update failed.<br>
> `Updater::BUSY` (504): Indicates that an update process is in progress.<br>

## Conclusion

The GitHub Release Updater is a simple and efficient way to keep your project up-to-date with the latest releases on GitHub. It is easy to use and can save you a lot of time and effort. If you have any questions or issues, please feel free create an issue.
