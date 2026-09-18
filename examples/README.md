# Examples

| Script | Shows | Needs server? |
|---|---|---|
| `basic_usage.php` | Creating messages, processing outbox | No |
| `custom_storage.php` | Implementing StorageInterface | No |
| `retry_policy.php` | Configuring retry behavior | No |
| `retry_aware_storage.php` | Letting the storage skip messages still in backoff | No |
| `shared_storage.php` | Two scoped processors over one storage, a terminal publish failure, `stats()` and `requeueFailed()` | No |
