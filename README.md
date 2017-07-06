## Scaffold

This package is for quickly generating migrations, models and controllers by passing in a php array describing your database structure.

Any relationships are automatically detected and the relevant code added to the migration and model.

## Installation

1. Require this package with composer:

```shell
composer require monkiibuild/scaffold
```

2. Open up `config/app.php` and add an entry to the providers array:

```php
'providers' => [
   // ...
   MonkiiBuilt\Scaffold\ScaffoldServiceProvider::class,
];
```

## Usage

The package adds the following console command

```shell
php artisan scaffold:make
```

Running this will create a sample `scaffold.php` file and put it in your app directory.

Edit this file to suit your requirements and then run `scaffold:make` again.

Using the ```--verbose``` option will cause the script to ask questions about which types of file to write and the desired namespace of your models and controllers.

#### A note on relationships
 
If one table is called users and another table has a field user_id then it will auto detect the relationship. 

It's not possible to tell from just this information if it's a oneToMany or a oneToOne so you will be prompted to confirm the type.
 
ManyToMany relationships are also auto detected when pivot tables are present. There is a restriction where pivot tables must only have 1 underscore for the auto detection to work correctly. For example user_role will work fine, but user_profile_role is too ambiguous and will not work.

### Sample input data

Here is a small sample of the input required to run the generator:

```php
$data['users'] = [
        'singular' => 'user',
        'columns' => [
            [
                'name' => 'id',
                'type' => 'increments',
            ],
            [
                'name' => 'name',
                'type' => 'string',
            ],
            [
                'name' => 'email',
                'type' => 'string',
                'modifiers' => [
                    'unique' => '',
                ],
            ],
            [
                'name' => 'password',
                'type' => 'string',
            ],
            [
                'type' => 'rememberToken',
            ],
            [
                'type' => 'timestamps',
            ],
            [
                'type' => 'softDeletes',
            ],
        ],
    ];
```

This input alone would be enough to generate a migration and a model.

A complete example is provided with the package.

### Similar packages

I wrote this as I could not find anything to quickly generate my migrations and models. Since have completed the package I've discovered [http://labs.infyom.com](http://labs.infyom.com) which looks like an alternative Laravel scaffolding package that you may want to checkout.  