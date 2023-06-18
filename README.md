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

If you're using a framework or other autoloading mechanism, you may not need to include this file manually.

To use this class with direct download, follow these steps:

- Retrieve the Updater.php file from the src directory in the repository.
- Put the Updater.php file in your project's directory.
- include the file to your project's to load the class.

## Usage

To initialize the Updater class and start the update process, follow these steps:
- Instantiate the GitUpdate class with the following parameters:

```
    use KoderZi\PhpGitHubUpdater\Updater;

    new Updater(
        string $username,
        string $repository,
        string $token,
        string $version,
        string $admin,
        string $mailer,
        array $exclude = ['path' => [], 'filename' => []]
    );
```

>- __$username__: Your GitHub username.
>- __$repository__: The name of your GitHub repository.
>- __$token__: The personal access token you generated earlier.
>- __$version__: The current version number of your project.
>- __$admin__: The email address of the admin who will receive an email in case of update failure.
>- __$mailer__: The email address that the email will be sent from.
>- __$exclude__: (Optional) An array of directories or files to exclude from the update.
```
// The $exclude array must have the format:
    $exclude =
    [
            'path' => [],
            'filename' => []
    ]
```

If a new release is available, the class will update your project automatically.

## Conclusion

The GitHub Release Updater is a simple and efficient way to keep your project up-to-date with the latest releases on GitHub. It is easy to use and can save you a lot of time and effort. If you have any questions or issues, please feel free to contact us.
