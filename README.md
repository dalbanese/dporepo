# DPO's 3D Repository

A Symfony project created on November 30, 2017, 8:53 pm.

A port from the [PHP Slim-based project](https://github.com/Smithsonian/dporepo_slim) to [Symfony 3.4](https://symfony.com/).

## Requirements
- PHP 7.2
- Symfony framework 3.4
- MySQL 5.7
- jQuery 1.12

## Installation

### Assumptions
- LAMP or WAMP environment has already been installed.
- Git has been installed

### Install webserver and database
TODO: needs specification for supported environments

### Prepare the website
#### Create a directory and clone dporepo into it
- cd /c/xampp/htdocs/
- mkdir dporepo
- git clone https://github.com/Smithsonian/dporepo.git dporepo/

#### Create the json directory and clone dporepo-schemas into it
- cd dporepo/web/
- mkdir json
- git clone https://github.com/Smithsonian/dporepo-schemas.git json/

#### Prepare database
- Create empty MySQL database, and database user account.
- Enable ldap extension and PDO extension, if not enabled, in php.ini

#### Parameters (app/config/parameters.yml)

If you have a filled-out `parameters.yml` file, move it into the app/config directory.

If not, you will be prompted during the installation to provide these settings.

The database settings must match the database and user account created in step Prepare database.

#### Install Symfony and Third Party Libraries using Composer

- Change directory into the web root. Run composer.
```php composer update```

- If PHP runs out of memory you can brute-force it to use unlimited memory.
``` php -d memory_limit=-1 composer update```

- TODO: Right now users have to disable the EDAN client within composer.json in order for install to work.

### Launch UI
#### Using a browser navigate to the homepage.
If you see PDO errors (can't find file), set unix_socket underneath doctrine:, dbal: within app/config/config.yml

#### Install the Application
Go to http://localhost:8080/ (Windows/XAMPP) http://127.0.0.1:8000/ (Mac) and click the "Install" button (switch the port number if need be)

If installation says it succeeded but you have no database, the most likely culprit is your version of MySQL doesn't support json fields. 
- TODO: Temp cheat, change the 2 JSON fields to varchar(8000) within database_create.sql
authoring_item, authoring_presentation tables

#### Register, and create a new user account.
Go to http://localhost:8080/login (Windows/XAMPP) http://127.0.0.1:8000/login (Mac) and click on "Register for an Account"

Set the Username to admin.

You should now have access to all repo functions.

#### Import Smithsonian Unit and ISNI data into the database

Download this .sql file and run this within the MySQL environment: http://gors.in/aj8C0h

### Install the DPO EDAN Bundle
Following the installation instructions out on GitHub

https://github.com/Smithsonian/DpoEdanBundle

#### Test endpoints (switch the port if need be)

http://127.0.0.1:8000/admin/edan/space%20shuttle

http://127.0.0.1:8000/admin/edan/space%20shuttle
