# Change Log

## 4.4.3
### Security
- Debug records of `RestListener` no longer carry whole objects. An application's log normalizer expands every
  property of an object, private ones included, so these records held request and response data. The messages are
  unchanged; their context now holds only class names, the controller name and other scalars:
  - "Handling kernel.controller": the controller name; `Class::method` for a class or object and method pair;
    otherwise the controller's class, or its type when it is not an object
  - "Handling kernel.view": the class of the controller result, or its type when it is not an object, instead of the
    event
  - "Handling kernel.exception": no context instead of the event, which held the exception, its trace and the error
    response. The "Exception class" record that follows still names the exception class
  - "Validating entity", "Mapped data to entity" and "Mapped query data to entity": the class of the entity, or its
    type when it is not an object, instead of the entity
  - "Setting modified at": the modification date as an ATOM string instead of the date object
- `ParameterToEntityMapBuilder` records carry no request data:
  - "Resolved entity from attribute data to " (debug): the class of the resolved entity instead of the entity
  - "Could not find the specified attribute name in the request" (warning): the names of the request attributes
    instead of their values
  - "Could not resolve entity from the attribute value" (warning): the attribute name under `attribute name` instead
    of the attribute value under `attribute value `
- `RestApi::dontLogRequest($controllerKey, 'url')` or `dontLogRequest($controllerKey, 'header')` no longer turns on
  the "Request content" record, which holds the whole request body. The first such call turned the two other parts
  on, `content` included, while the default keeps `content` off. Now the other parts keep their defaults
- The "Invalid data exception caught" notice record of `RestListener` holds only the names of the invalid properties,
  under `invalid properties`, instead of their validation messages. A message can quote another submitted field, such
  as the compared value of `EqualTo` with `propertyPath`. The names carry no array keys or indexes: each keeps only
  the part before the first `[` and the part after the last `]`, so a key that itself contains brackets is dropped
  too. `[jane@example.com].email` and `children[0].iban` are logged as `email` and `children.iban`, each name once
- Unchanged: the `ExceptionLogger` record still carries the exception message, with the exception in its context. The
  message can be a validation message: `ValidatorAwareDenormalizer` uses the first violation message as the exception
  message. An application must not put a request credential into an exception message or a validation message
- Unchanged: `RequestLogger::log()` still writes the request body when the parts it gets turn `content` on. `RestApi`
  turns it on only through a subclass that sets `content` in `$requestLoggingParts`

## 4.4.2
### Security
- The "Request header" debug record of `RequestLogger` (channel `paysera_rest.rest_logger`) no longer carries the
  `Authorization` header. It held the caller's credential: a bearer token, a MAC signature or Basic credentials. The
  record keeps `Content-Type`
- `RestListener::onKernelException()` no longer writes the exception's string form (message and stack trace) to a debug
  record. The record goes to the matched RestApi's logger, or to `paysera_rest.rest_listener` when the request matches
  no RestApi. The message could quote a request credential, such as the nonce of a MAC `Authorization` header. The
  record now carries the exception class only
- The "Setting error response" debug record carries the response status code instead of the response body, which could
  repeat the exception message
- The "Invalid data exception caught" notice record of `RestListener` no longer appends the exception's string form to
  its message. Its context still holds the invalid properties
- `ExceptionLogger` is unchanged: its record still carries the exception message, with the exception in its context.
  An application must not put a request credential into an exception message
### Changed
- Tests run on GitHub Actions for PHP 7.0, 7.1 and 7.4. The unused Travis CI configuration is removed

## 4.4.1
### Fixed
Made service `paysera_rest.service.property_path_converter.camel_case_to_snake_case` public as it is being retrieved directly from the container.

## 4.4.0

### Changed
- Dropped support for PHP 5, Symfony 2
- Now requiring Symfony components individually, rather than requiring package `symfony/symfony`
- Updated PHPUnit and mockery/mockery
- Removed usage of use deprecated `Symfony\Component\Security\Core\Role\RoleHierarchyInterface`, now using `Symfony\Component\Security\Core\Role\RoleHierarchy`. 

### Fixed
- Code style fixes

## 4.3.0
### Added
- `Paysera\Bundle\RestBundle\ApiManager::createErrorFromException()` - checks if exception contains status code *400* and creates `ApiException::InvalidRequest` error

## 4.2.2
### Fixed
- `Paysera\Bundle\RestBundle\Listener\RestListener::onKernelException()` increased listener priority to `10`. Since Symfony 3.3 the priority of `ExceptionListener::onKernelException` was changed to `1`.

## 4.2.1
### Changed
- `\Paysera\Bundle\RestBundle\RestApi::getValidationGroups()` no longer returns `null` if `\Paysera\Bundle\RestBundle\RestApi::$globalValidationGroups` is empty.

## 4.2.0
### Added
- New optional bundle configuration parameter `locales`

### Changed
- `\Symfony\Component\HttpFoundation\Request::getLocale` now can return preferred locale from the `Accept-Language` header, which resolves from the `locales` parameter in the bundle configuration

## 4.1.1
### Added
- Moved `Paysera\Bundle\RestBundle\Listener\RestListener::onKernelException` logging to service `Paysera\Bundle\RestBundle\Service\ExceptionLogger`

## 4.0.0
###  Changed
- `Paysera\Bundle\RestBundle\RestApi` property `propertyPathConverter` now has default value set to `CamelCaseToSnakeCaseConverter`

## 3.0.0
### Changed 
- `Paysera\Bundle\RestBundle\Entity\Error` all properties are now private instead of protected
- `Paysera\Bundle\RestBundle\Exception\ApiException` all properties are private instead of protected
- `Paysera\Bundle\RestBundle\Exception\ApiException` 7th construct argument now is `violation` of type `Violation[]` instead of `codes` of type `string[]`
- 400 error response will no longer contain `errors` property instead if `error_properties_codes` property
### Removed
- `Paysera\Bundle\RestBundle\Entity\Error` removed `errorCodes` property and related getters/setters
- `Paysera\Bundle\RestBundle\Entity\Error` removed `toArray` method
- `Paysera\Bundle\RestBundle\Exception\ApiException` removed property getter and setter of `codes` property

## 2.1.0
### Changed
- `_format` route attribute is no longer used to detect Request or Response format. 
`Content-Type` or `Accept` headers should be used accordingly. 

## 2.0.3
### Deprecated
- Deprecated `_format` route attribute. In future releases request format will be always taken from `Content-Type` header.

## 2.0.0
### Changed
- Update factory service syntax
