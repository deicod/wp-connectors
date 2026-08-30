# 0002 — Model construction & metadata directory

Verified against: `wp-includes/php-ai-client/src/Providers/AbstractProvider.php`,
`…/Providers/ApiBasedImplementation/AbstractApiProvider.php`,
`…/Providers/ApiBasedImplementation/AbstractApiBasedModelMetadataDirectory.php`,
`…/Providers/OpenAiCompatibleImplementation/AbstractOpenAiCompatibleModelMetadataDirectory.php`,
`…/Providers/ApiBasedImplementation/{ListModels,GenerateText}ApiBasedProviderAvailability.php`,
`…/Providers/Models/DTO/{ModelMetadata,SupportedOption}.php`,
`…/Providers/Models/Enums/{CapabilityEnum,OptionEnum}.php`,
`anthropic-plugin/src/AnthropicProvider.php`, `anthropic-plugin/src/AnthropicModelMetadataDirectory.php`.

## Provider factories (`AbstractProvider`, all `final static` accessors)

```php
AbstractProvider::metadata(): ProviderMetadata                    // cached per class
AbstractProvider::model( string $modelId, ?ModelConfig $c = null ): ModelInterface
AbstractProvider::availability(): ProviderAvailabilityInterface   // cached per class
AbstractProvider::modelMetadataDirectory(): ModelMetadataDirectoryInterface
```

A concrete provider implements four `abstract protected static` factories:

```php
createModel( ModelMetadata $m, ProviderMetadata $p ): ModelInterface;
createProviderMetadata(): ProviderMetadata;
createProviderAvailability(): ProviderAvailabilityInterface;
createModelMetadataDirectory(): ModelMetadataDirectoryInterface;
```

`AbstractApiProvider` adds `abstract protected static function baseUrl(): string` and
`public static function url( string $path = '' ): string` (single-slash join). This is
where SPEC §3.3's rule applies: `baseUrl()` stays the canonical intl-general URL; the
plan×region request URL is resolved at request time in the model/directory layer —
`AbstractApiBasedModelMetadataDirectory::createRequest()` is overridable exactly for
this (anthropic directory overrides it to call `AnthropicProvider::url($path)`).

Model flow: `model($id)` → `modelMetadataDirectory()->getModelMetadata($id)` →
`createModel()` → optional `setConfig(ModelConfig)`. `ProviderRegistry::getProviderModel()`
additionally calls `bindModelDependencies()` which injects the HTTP transporter and the
provider's `RequestAuthenticationInterface` into the model.

## `ProviderMetadata` (construction order matters)

```php
new ProviderMetadata(
    string $id,                                  // ^[a-z0-9\-_]+$
    string $name,
    ProviderTypeEnum $type,                      // ProviderTypeEnum::cloud()
    ?string $credentialsUrl = null,
    ?RequestAuthenticationMethod $authenticationMethod = null, // apiKey() or null
    ?string $description = null,                 // SDK >= 1.2.0 only
    ?string $logoPath = null                     // SDK >= 1.3.0 only
);
```

The anthropic plugin builds `$providerMetadataArgs` incrementally and appends
description/logoPath only when `version_compare( AiClient::VERSION, …, '>=' )` — the
pattern our providers copy (see record 0003).

## Model metadata: capabilities & options

```php
new ModelMetadata(
    string $id,                 // 'glm-5.3'
    string $name,               // display name
    CapabilityEnum[],           // textGeneration(), chatHistory()
    SupportedOption[]           // new SupportedOption( OptionEnum::x(), $allowedValues )
);
```

Verified enum members used by the reference plugin (v1.0.4):

- `CapabilityEnum::textGeneration()`, `::chatHistory()`
- `OptionEnum::systemInstruction()`, `::maxTokens()`, `::stopSequences()`,
  `::outputMimeType()`, `::outputSchema()`, `::functionDeclarations()`,
  `::customOptions()`, `::inputModalities()`, `::outputModalities()`,
  `::temperature()`, `::topP()`, `::topK()`, `::webSearch()`
- `ModalityEnum::text()`, `::image()`, `::document()` — `inputModalities` allowed
  values are lists of modality lists, e.g. `[[ModalityEnum::text()]]`.
- `SupportedOption`'s second constructor argument constrains allowed values (e.g.
  `outputMimeType` with `['text/plain', 'application/json']`).

Sorting: the reference directory sorts with a `usort()` callback
(`modelSortCallback`) preferring newer/family models first. Our z.ai catalogs sort
newest GLM first, per SPEC §3.3.

## Availability implementations

- `ListModelsApiBasedProviderAvailability( ModelMetadataDirectoryInterface $directory )`
  — probes the model-list endpoint. Suitable for z.ai both surfaces once `/models`
  works (record 0006).
- `GenerateTextApiBasedProviderAvailability( ModelInterface $model )` — sends a
  1-token text request; throws are swallowed → `false`.
- Both implement `isConfigured(): bool` and are network-calling. They are also wired
  with the provider's transporter/auth by the registry
  (`setHttpTransporterForProvider` / `setRequestAuthenticationForProvider`), i.e.
  **the SDK core screen availability check performs HTTP**. Consequence: OAuth
  providers (M3+) must return a read-only cached-state availability instead (SPEC
  §4.4), which is possible because `createProviderAvailability()` returns our own
  implementation of `ProviderAvailabilityInterface`.

## Notes

- Directories use `WithDataCachingTrait` (`CachesDataInterface`) — model lists are
  cached per instance; a fresh directory instance per registry wiring is the norm.
- `RequestAuthenticationMethod` is **not** a native PHP enum; it extends the SDK's
  `AbstractEnum` (PHP 7.4-compatible) with magic statics (`apiKey()`) and instance
  helpers (`isApiKey()`, `getImplementationClass()` → `ApiKeyRequestAuthentication::class`).
  `ApiKeyRequestAuthentication::getApiKey()` exists (anthropic directory uses it to
  re-wrap the key in a provider-specific auth class).
