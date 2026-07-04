# Example listeners for your application (not autoloaded by the bundle)

Copy the PHP classes from this directory into `src/Yopass/` (or similar) and register them:

```yaml
# config/services.yaml
services:
    App\Yopass\AccessControl\TeamShareListListener: ~
    App\Yopass\AccessControl\TeamShareAccessListener: ~
    App\Yopass\AccessControl\IndividualShareGrantListener: ~
    App\Yopass\AccessControl\RoleBasedShareAccessListener: ~
```

See [docs/examples/AccessControl.md](../docs/examples/AccessControl.md) for event reference and integration notes.
