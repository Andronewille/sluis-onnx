# Sluis ONNX

A local ONNX model as one more [Sluis](https://github.com/Andronewille/sluis) recogniser: the
names, towns and organisations in prose that no pattern and no list can find. Still offline — the
weights are read from disk, and a run that finds none stops rather than fetching them.

It needs PHP's FFI extension and 178 MB of weights, which is why it is not part of the core.

```sh
composer require andronewille/sluis-onnx
vendor/bin/transformers download Xenova/bert-base-multilingual-cased-ner-hrl \
    token-classification --cache-dir=/models
```

Composer asks whether `codewithkyrian/platform-package-installer` may run: it picks the ONNX Runtime
build for this machine, and the model does not load without it.

```php
use Sluis\Onnx\{Onnx, Profile, Transformers};
use Sluis\Sluis;

$profile = Profile::ner();
$sluis = Sluis::nederlands()->plus(new Onnx(new Transformers($profile, '/models'), $profile));
```

This repository is a read-only mirror, split out of
[Andronewille/sluis](https://github.com/Andronewille/sluis). The issues and the tests are there,
and so is [what the model was measured to find and to miss](https://github.com/Andronewille/sluis/blob/main/docs/design.md#the-model).

Licensed under the [EUPL-1.2](LICENSE).
