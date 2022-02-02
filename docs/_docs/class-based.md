---
layout: docs 
title: Class-based Settings
nav_order: 3
---

# Laravel Settings

{: .no_toc }

<details open markdown="block">
  <summary>
    Contents
  </summary>
  {: .text-delta }
1. TOC
{:toc}
</details>

---

## Class-based

Although the settings we've covered work well for most sites, sometimes you want to make each of your settings a little more explicit to help you keep track of them.

This is where class-based settings come in. Rather than a setting being represented with a key, your setting is instead a class. This not only allows you to benefit from typehinting when getting and setting values, but also gives a few useful features you can make use of.

### Defining a class setting

### Getting/setting values

### Registering settings

### Settings in depth

## Migrating to class-based from anonymous

moving over and using aliases

### Aliases

For common settings, you can alias the getters to a single function. Rather than using `\Settings\Setting::getValue(\Acme\Setting\SiteName::class)`, you can use `\Settings\Setting::getSiteName()`.

By doing this you won't get IDE typehinting, but it is a more concise way to refer to settings.

To alias a setting like this, add it to the config file

```php
<?php

return [
    'aliases' => [
        'siteName' => \Acme\Setting\SiteName::class,
        ...
    ]
];
```

You can also add it directly in your service provider `boot` method

```php
public function boot()
{
    \SettingsSetting::alias('siteName', \Acme\Setting\SiteName::class);
}
```

Aliasing in this way also makes your class-based settings accessible through the alias, so site name can now be accessed with `settings(\Acme\Setting\SiteName::class)` or `settings('siteName')`.


### Class-based settings

A class-based setting has generally the same information, but defines functions to return them rather than setting them in arguments.

```php
<?php

use Settings\Schema\Setting;

class SiteName extends Setting
{

    /**
     * The default value of the setting.
     *
     * @return mixed
     */
    public function defaultValue()
    {
        return 'My Site';
    }

    /**
     * The field schema to show the user when editing the value.
     *
     * @throws \Exception
     * @return Field
     */
    public function fieldOptions(): \FormSchema\Schema\Field
    {
        return \FormSchema\Generator\Field::textInput($this->key())->setValue($this->defaultValue());
    }

    /**
     * Return the validation rules for the setting.
     *
     * The key to use for the rules is data. You may also override the validator method to customise the validator further
     *
     * @return array
     */
    public function rules(): array|string
    {
        return 'string|min:2|max:20';
    }
    
    /**
     * @return array
     */
    public static function group(): array
    {
        return ['branding', 'appearance'];
    }
}
```

These classes should extend a setting type such as `Settings\Schema\UserSetting` or `Settings\Schema\GlobalSetting`. See more about creating a custom type at the end of this page.

### Customising your setting

#### Form Field

Form fields are defined using the [form schema generator](https://tobytwigger.github.io/form-schema-generator/). You can define any field you need here, including complex fields that return objects.

The input name for the field is defined in `$this->key()`, and the default value in `$this->defaultValue()` so to define a simple text field you'd use this plus a label/hint/other fields.

When using anonymous settings, hardcode the key and value and just pass the result of the field generator directly to `::create`.

```php
    public function fieldOptions(): \FormSchema\Schema\Field
    {
        return \FormSchema\Generator\Field::textInput($this->key())->setValue($this->defaultValue());
    }

```

Fields are currently a required property of any setting, to allow you to dynamically create setting pages. You can learn more about how to integrate your frontend with the form schema in the [integrate documentation](integrating.md).

#### Validation

To ensure the settings entered into the database are valid, you can define rules in the `rules` array. This can be an array or string of rules, that will validate a valid value. There's no need to put `required`/`optional` rules in, but do include `nullable` if the option can be null.

```php
    public function rules(): array|string
    {
        return 'string|min:2|max:20';
    }
```

#### Groups

Groups are a way to order settings to the user. By grouping together similar settings (such as those related to the site theme, authentication, emails etc), it helps users quickly find what they're looking for.

To define a group, define a `group` function. This should return an array of groups the setting is in. When retrieving a form schema to represent settings, the first group will be taken as the group, and therefore the first group should be the 'main' group.

```php
    public function group(): array
    {
        return ['branding', 'appearance'];
    }
```

See the integrate section for information about how to add metadata to these.

#### Encryption

The value of all settings are encrypted automatically, since it adds very little overhead. If the data in the setting is not sensitive and you'd rather not encrypt it, set a public `$shouldEncrypt` property to false in your setting.

```php
    protected boolean $shouldEncrypt = false;
```

You can also make the default behaviour be that encryption is not automatic, but can be turned on with `$shouldEncrypt = true`. To do this, set `encryption` to `false` in the config file. Anonymous settings use this default behaviour to determine if settings should be encrypted.

#### Complex data types

All values in the database are automatically serialised to preserve type. This means that arrays and objects will all be saved and retrieved in the correct format, so you don't have to worry about how your setting is saved.

If you want to control how the setting is saved in the database, implement the `\Settings\Contract\CastsSettingValue` interface on your setting. You will need to define a `castToString` and `castToValue` functions on the setting which will convert your validated setting value to a database-friendly string and back.

This example would handle a complex data object, such as something returned from an API client.

```php
    public function castToString(\My\Api\Result $value): string
    {
        return json_encode([
            'id' => $value->getId(),
            'result' => $value->getResult()
        ]);
    }

    public function castToValue(string $value): \My\Api\Result
    {
        $value = json_decode($value, true);
        
        return new \My\Api\Result($value['id'])
            ->getResult($value['result']);
    }
```

## Registering

You can then register settings in the `boot` function of a service provider using the facade, or replace `\Settings\Setting::` with `settings()->` to use the helper function.

You can also register information about groups, which will be automatically pulled into any form schemas you extract from settings.

```php
    public function boot()
    {
        \Settings\Setting::register(new \Acme\Setting\SiteName());
        \Settings\Setting::register([
            // Create a new class instance manually
            new \Acme\Setting\SiteName(),
            
             // Letting the service container build the setting means you can inject dependencies into the setting construct.
            $this->app->make(\Acme\Setting\SiteTheme::class)
        ]);
        
        \Settings\Setting::register(new \Acme\Setting\SiteName(), ['extra', 'groups', 'for', 'the', 'setting']);
        
        \Settings\Setting::registerGroup(
            'branding', // Group Key
            'Branding', // Title for the group
            'Settings related to the site brand' // Description for the group
        );
    }
```

Anonymous settings are automatically registered for you when using `create::`, `createUser::` or `createGlobal::`. If you want to just create a setting rather than register one, you can use the factory directly.

```php
$setting = \Settings\Anonymous\AnonymousSettingFactory::make(string $type, string $key, mixed $defaultValue, Field $fieldOptions, array $groups = ['default'], array|string $rules = [], ?\Closure $resolveIdUsing = null);
\Setting\Setting::register($setting);
```

You can also register settings and groups in the config. You need to make sure these settings can be resolved from the service container - if your setting doesn't rely on any dependencies being passed in then you won't need to worry about this.

```php
<?php

// config/settings.php

return [

    'settings' => [
        \Acme\Setting\SiteName::class,
        \Acme\Setting\SiteTheme::class,
    ],
    'groups' => [
        'branding' [
            'title' => 'Branding',
            'subtitle' => 'Settings related to the site brand'
        ],
    ]
];
```