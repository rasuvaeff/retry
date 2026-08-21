# rasuvaeff/retry

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/retry/v)](https://packagist.org/packages/rasuvaeff/retry)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/retry/downloads)](https://packagist.org/packages/rasuvaeff/retry)
[![Build](https://github.com/rasuvaeff/retry/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/retry/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/retry/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/retry/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/retry/actions/workflows/static-analysis.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/retry/php)](https://packagist.org/packages/rasuvaeff/retry)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)
[English version](README.md)

Retry поверх замыканий с фиксированной/экспоненциальной задержкой (backoff),
full/additive jitter, временными бюджетами, тестируемыми интерфейсами
clock/sleeper/randomizer, хуками наблюдаемости и декоратором PSR-18 HTTP-клиента,
учитывающим `Retry-After`.

> Используете AI-ассистента? В [llms.txt](llms.txt) — компактный API-справочник,
> которым можно поделиться с моделью.
> Проекты с Composer-плагином [llm/skills](https://github.com/roxblnfk/skills)
> дополнительно получают agent-скилл этого пакета в `.agents/skills/`
> автоматически при установке.

## Требования

- PHP 8.3+
- `psr/clock` ^1.0
- `psr/http-client` ^1.0
- `psr/http-message` ^1.0 || ^2.0
- `rasuvaeff/duration` ^1.0

## Установка

```bash
composer require rasuvaeff/retry
```

## Использование

```php
use Rasuvaeff\Retry\Retry;

$value = Retry::new()
    ->maxAttempts(3)
    ->withExponential(baseMs: 100, multiplier: 2.0, capMs: 30_000)
    ->jitter(factor: 0.2)
    ->retryOn(RuntimeException::class)
    ->retryIf(fn(Throwable $e): bool => $e->getCode() >= 500)
    ->stopIf(fn(Throwable $e): bool => $e instanceof InvalidArgumentException)
    ->stopAfterMs(budgetMs: 10_000)
    ->onRetry(fn(AttemptRecord $record): null => null)
    ->run(fn(): string => flakyOperation());
```

Именованные фабрики политик возвращают готовый builder:

```php
Retry::fixed(delayMs: 500, maxAttempts: 3);
Retry::exponential(maxAttempts: 3);
Retry::immediate(maxAttempts: 3);
```

Настройте builder методами `with*`:

```php
Retry::new()->withExponential(baseMs: 100, multiplier: 2.0, capMs: 30_000);
Retry::new()->withFixed(delayMs: 500);
Retry::new()->withImmediate();
Retry::new()->withClock(new SystemClock());
```

Каждая фабрика и каждый метод builder'а — это настоящий, статически
анализируемый метод; магических псевдонимов `__call`/`__callStatic` нет.

### Duration

У каждой точки входа в миллисекундах (`int`) есть пара из `rasuvaeff/duration`:
задержки и бюджеты можно выражать как типобезопасные value-объекты `Duration`
вместо неоднозначных целых. API в `int`-миллисекундах не изменилось; варианты с
Duration — чисто аддитивные.

```php
use Rasuvaeff\Duration\Duration;

Retry::fixedFor(delay: Duration::seconds(1), maxAttempts: 3);
Retry::exponentialFor(base: Duration::millis(100), cap: Duration::seconds(30));

Retry::new()
    ->withExponentialFor(base: Duration::millis(100), cap: Duration::seconds(30))
    ->stopAfter(budget: Duration::seconds(10));

RetryPolicy::fixedFor(delay: Duration::seconds(1));
```

`AttemptRecord` и `Http\HttpAttemptRecord` отдают на выходе те же значения:
`delay(): ?Duration` (`null` на терминальной записи) и `elapsed(): Duration`
рядом с уже существующими полями `delayMs`/`elapsedMs`.

По умолчанию retry срабатывает на любом `\Exception`. `\Error` (например,
`\TypeError`, assertion failure) **не** повторяется — включите явно через
`retryOn(\Error::class)`. `retryOn()` **заменяет** список классов (поэтому им можно
сузить дефолт), а `retryIf()` и `stopIf()` наслаивают дополнительные предикаты.

### Jitter

`jitter()` выбирает стратегию через enum `Jitter\JitterMode`. `factor`
применяется только к `Additive` (для `Full` и `None` игнорируется).

```php
use Rasuvaeff\Retry\Jitter\JitterMode;

Retry::new()->jitter(factor: 0.2, mode: JitterMode::Additive); // equal jitter: [(1-factor)·delay, delay]
Retry::new()->jitter(mode: JitterMode::Full);                  // random in [0, delay]
Retry::new()->jitter(mode: JitterMode::None);                  // no jitter
```

`Additive` — это «equal jitter»: он размазывает задержку только **вниз**, поэтому
значение никогда не превышает задержку backoff'а, а `capMs` остаётся жёстким
потолком. Либо внедрите стратегию напрямую: `withJitter(new Jitter\FullJitter())`.

### Повтор по возвращаемому значению

`retryIfResult()` повторяет попытку, когда операция завершилась успешно, но
значение неприемлемо (исключение не требуется). При исчерпании попыток
отвергнутое значение доступно через `RetryExhausted::lastException` (это
`UnacceptableResult`).

```php
$value = Retry::new()
    ->maxAttempts(5)
    ->retryIfResult(fn(Response $r): bool => $r->status === 'pending')
    ->run(fn(): Response => $api->poll());
```

### Временной бюджет

`stopAfterMs()` ограничивает суммарное wall-clock-время на повторы. Попытка 1
выполняется всегда; следующая пропускается, если `elapsed + nextDelay` превысит
бюджет, поэтому неиспользованное время сна не приплюсовывается.

```php
Retry::new()
    ->maxAttempts(maxAttempts: 10)
    ->withExponential(baseMs: 200)
    ->stopAfterMs(budgetMs: 5_000)
    ->run(fn(): string => flakyOperation());
```

Каждая `AttemptRecord` в `RetryExhausted::history` несёт `elapsedMs` —
миллисекунды между стартом `run()` и этой попыткой. Колбэки `onRetry` получают
полную `AttemptRecord`; колбэки `onExhausted` получают `RetryExhausted`.
`RetryExhausted::reason` — это `ExhaustionReason` (`MaxAttempts` или `TimeBudget`),
объясняющий, почему цикл сдался. Терминальная `AttemptRecord` имеет `delayMs` =
`null` (после неё нет сна).

### Интеграция с Result

Пакет не зависит от типа Result. Чтобы получить `Result<T, RetryExhausted>` без
исключений, оберните `run()` своей Result-библиотекой. С `rasuvaeff/result`:

```php
use Rasuvaeff\Result\Result;

$result = Result::fromThrowable(
    fn() => Retry::new()->maxAttempts(3)->run(fn(): string => flakyOperation()),
);

$value = $result->unwrapOr('fallback');
```

`Result::fromThrowable()` ловит любой `Throwable` из `run()` (включая
`RetryExhausted`), поэтому канал ошибок — это `Throwable`, если не сузить его
через `mapErr()`.

### Декоратор PSR-18

`RetryingHttpClient` оборачивает любой PSR-18 клиент. `retryOnResponse` решает,
какие ответы повторять; в `Http\RetryDecisions` есть готовые предикаты.

```php
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Rasuvaeff\Retry\Http\HttpAttemptRecord;
use Rasuvaeff\Retry\Http\HttpRetryExhausted;
use Rasuvaeff\Retry\Http\RetryDecisions;
use Rasuvaeff\Retry\Http\RetryingHttpClient;
use Rasuvaeff\Retry\RetryPolicy;

$client = new RetryingHttpClient(
    inner: $psr18Client,
    policy: RetryPolicy::exponential(maxAttempts: 3, baseMs: 100),
    retryOnResponse: RetryDecisions::onlyIdempotent(RetryDecisions::transient()),
    respectRetryAfter: true,
    maxRetryAfterMs: 300_000,
    budgetMs: null,
    retryOnException: null,
    throwOnExhausted: false,
    onRetry: [fn(HttpAttemptRecord $record): null => null],
    onExhausted: [fn(HttpRetryExhausted $exhausted): null => null],
);
```

И `retryOnResponse`, и `retryOnException` получают `RequestInterface` вторым
аргументом, поэтому повторы можно привязывать к HTTP-методу:

```php
retryOnResponse: fn(ResponseInterface $r, RequestInterface $req): bool
    => $req->getMethod() === 'GET' && $r->getStatusCode() >= 500,
```

Готовые предикаты ответа:

| Предикат | Когда повторяет |
|---|---|
| `RetryDecisions::serverErrors()` | Любой 5xx (500–599). |
| `RetryDecisions::rateLimited()` | Только 429. |
| `RetryDecisions::transient()` | 408, 425, 429, 500, 502, 503, 504. |
| `RetryDecisions::onlyIdempotent($inner)` | Оборачивает `$inner`; повторяет **только** идемпотентные методы (GET, HEAD, PUT, DELETE, OPTIONS, TRACE). |

Аргументы конструктора помимо `inner` / `policy` / `retryOnResponse`:

| Аргумент | По умолчанию | Эффект |
|---|---|---|
| `clock` | `Clock\SystemClock` | Часы PSR-20 для разбора HTTP-date в `Retry-After` и тайминга бюджета. |
| `respectRetryAfter` | `true` | Уважать заголовок `Retry-After` от сервера. |
| `maxRetryAfterMs` | `300_000` | Верхняя граница задержки из `Retry-After`; `null` отключает ограничение. |
| `budgetMs` | `null` | Суммарный wall-clock-бюджет; повтор пропускается, когда `elapsed + delay` превышает его. |
| `retryOnException` | `null` | Предикат `fn(ClientExceptionInterface, RequestInterface): bool`; `null` повторяет каждое транспортное исключение. Несовпадающие исключения пробрасываются как есть. |
| `throwOnExhausted` | `false` | При `true` выбрасывает `Http\HttpRetryExhausted` (с историей) при исчерпании вместо возврата последнего ответа / проброса последнего транспортного исключения. |
| `onRetry` | `[]` | Колбэки `fn(HttpAttemptRecord): void`, срабатывают перед сном каждой повторной попытки. |
| `onExhausted` | `[]` | Колбэки `fn(HttpRetryExhausted): void`, срабатывают при любом исчерпании (`maxAttempts` или `budgetMs`); аргумент несёт `attempts` и полную `history`. |

Бюджет попыток один и покрывает как повторяемые ответы, так и транспортные
исключения PSR-18, поэтому внутренний клиент вызывается не более `maxAttempts` раз.
Когда повторяемый ответ несёт корректный заголовок `Retry-After`, эта задержка
замещает настроенный backoff (delta-seconds либо IMF-fixdate; устаревшие формы
RFC 850 и asctime игнорируются, как и delta-seconds слишком большие, чтобы быть
пригодной задержкой, и даты, не проходящие точный round-trip формата
IMF-fixdate — всё это откатывается на настроенный backoff). Задержка от сервера
идёт без jitter и ограничивается `maxRetryAfterMs`. Транспортные исключения
всегда откатываются на backoff (`Retry-After` недоступен). Передайте
`respectRetryAfter: false`, чтобы отключить обработку заголовка.

Перед каждой повторной отправкой тело запроса перематывается, если оно
seekable — PSR-7 stream-тела хранят позицию, и без перемотки попытка 2+ POST
молча ушла бы с пустым телом. **Запросы с non-seekable телом небезопасно
повторять**: тело нельзя воспроизвести, повторная отправка передаст лишь
остаток потока (обычно ничего). Используйте seekable-тело либо ограничьте
retry идемпотентными/безтелесными запросами через `retryOnResponse` /
`RetryDecisions::onlyIdempotent()`.

`Http\HttpRetryExhausted` реализует `Psr\Http\Client\ClientExceptionInterface`,
поэтому остаётся в рамках контракта PSR-18 — код, ловящий `ClientExceptionInterface`,
продолжит его ловить.

Каждая `HttpAttemptRecord` несёт `attempt`, `delayMs`, `elapsedMs` и ровно одно
из `response` / `exception` (никогда обоих, никогда ни одного). Внедрите
`Clock\FakeClock`, чтобы сделать задержки `Retry-After` и тайминг бюджета
детерминированными в тестах.

### Публичный API

| Класс | Описание |
|---|---|
| `Retry` | Иммутабельный builder retry и раннер замыканий. |
| `RetryPolicy` | Переиспользуемый объект политики для декораторов. |
| `RetryPolicyInterface` | Контракт политики только для чтения. |
| `RetryExhausted` | Исключение с попытками, последним исключением, историей и `reason`. |
| `ExhaustionReason` | Enum: `MaxAttempts` или `TimeBudget`. |
| `AttemptRecord` | Одна неудачная попытка: номер попытки, `delayMs` (`null` на терминальной), elapsed и исключение. |
| `UnacceptableResult` | Исключение, несущее значение, которое отверг предикат `retryIfResult()`. |
| `BackoffStrategy\BackoffStrategyInterface` | Контракт задержки backoff. |
| `BackoffStrategy\FixedBackoff` | Константная задержка. |
| `BackoffStrategy\ExponentialBackoff` | Экспоненциальная задержка с потолком. |
| `BackoffStrategy\ImmediateBackoff` | Нулевая задержка. |
| `Jitter\JitterInterface` | Контракт jitter. |
| `Jitter\JitterMode` | Enum, выбирающий стратегию для `jitter()`: `Additive`, `Full`, `None`. |
| `Jitter\FullJitter` | Случайная задержка между нулём и вычисленной. |
| `Jitter\AdditiveJitter` | Equal jitter: размазывает задержку вниз, никогда не выше неё. |
| `Jitter\NoJitter` | Оставляет задержку без изменений. |
| `Clock\SystemClock` | Часы PSR-20 на системном времени. |
| `Clock\FakeClock` | Мутабельные часы PSR-20 для тестов с `advanceMs()`. |
| `Sleeper\SleeperInterface` | Контракт сна. |
| `Sleeper\SystemSleeper` | Реализация на `usleep()`. |
| `Sleeper\FakeSleeper` | Тестовый sleeper, записывающий задержки. |
| `Randomizer\RandomizerInterface` | Контракт float-рандомайзера. |
| `Randomizer\SystemRandomizer` | Runtime-рандомайзер. |
| `Randomizer\FixedRandomizer` | Детерминированный тестовый рандомайзер. |
| `Http\RetryingHttpClient` | Декоратор PSR-18 с поддержкой `Retry-After`. |
| `Http\RetryDecisions` | Готовые предикаты ответа + обёртка `onlyIdempotent()`. |
| `Http\HttpAttemptRecord` | Одна HTTP-попытка: attempt, delay, elapsed и `response` xor `exception`. |
| `Http\HttpRetryExhausted` | Дескриптор исчерпания, передаётся в каждый хук `onExhausted` и выбрасывается при `throwOnExhausted`; `ClientExceptionInterface`, несущий `attempts` и историю. |
| `Http\RetryAfterParser` | Разбирает `Retry-After` в миллисекунды через часы PSR-20. |

## Долго живущие воркеры (RoadRunner, Swoole, FrankenPHP)

Пакет безопасно переиспользовать между запросами в долгоживущем воркере: каждый
класс — `final readonly` без глобального или статического изменяемого состояния, а
`SystemClock` на каждом вызове `now()` перечитывает wall clock (не фиксируется).
Постройте `Retry` или `RetryingHttpClient` один раз и делитесь им.

Подводный камень — **сон в backoff'е**. `SystemSleeper::sleepMs()` вызывает
`usleep()`, который **блокирует текущий воркер** на всю задержку. Воркер
обслуживает по одному запросу за раз, поэтому ожидающий повтор — экспоненциальный
backoff до `capMs` (по умолчанию 30 с) либо серверный `Retry-After` до
`maxRetryAfterMs` (**по умолчанию 300_000 = 5 мин**) — держит воркер всё это
время. При фиксированном пуле горстка запросов с длинными повторами может
истощить пул и обрушить throughput.

Рекомендации:

| Рычаг | Действие |
|---|---|
| Ограничить серверные задержки | Снизьте `maxRetryAfterMs` (до нескольких секунд), чтобы враждебный/большой `Retry-After` не прибивал воркер; держите `capMs` и `budgetMs` скромными. |
| Снести длинные повторы с горячего пути | Выполняйте повторы со значимым backoff из очереди / RoadRunner Jobs-воркера, а не из синхронного request-воркера. |
| Кооперативный сон | Внедрите неблокирующий `SleeperInterface` через `Retry::withSleeper()` (ядро) либо sleeper политики (HTTP), если рантайм предлагает кооперативное планирование (например, sleep корутины Swoole). |

`RetryingHttpClient` оборачивает **исходящий** PSR-18 клиент; к inbound-обработке
PSR-7/PSR-15 запросов сервером он отношения не имеет.

## Безопасность

Пакет только вызывает замыкания и PSR-18 клиенты, поставляемые приложением. Он не
инспектирует креды, URL'ы, тела запросов и тел ответов. Хуки получают исключения и
тайминг-метаданные; не логируйте секреты из сообщений исключений без
прикладного редактирования. Значения `Retry-After` трактуются как непрозрачные
timing-подсказки и не используются для построения URL или запросов.

**Идемпотентность.** `RetryingHttpClient` повторяет любой переданный запрос,
включая неидемпотентные методы (`POST`, `PATCH`), что может породить дублированные
побочные эффекты, если сервер успел обработать первый запрос до того, как ответ
упал. Привязывайте повторы к идемпотентным методам через
`RetryDecisions::onlyIdempotent(...)` или собственный request-aware-предикат, если
только эндпоинт безопасно повторять (например, защищён idempotency-key).

## Примеры

См. [examples/](examples/) — запускаемые скрипты.

| Скрипт | Показывает | Нужен сервер? |
|---|---|---|
| `basic.php` | Retry замыкания с FakeSleeper и фиксированным backoff | нет |
| `time_budget.php` | `stopAfterMs` с FakeClock + elapsed в истории | нет |
| `result_retry.php` | `retryIfResult` по возвращаемому значению; `UnacceptableResult` при исчерпании | нет |
| `retry_after.php` | Декоратор PSR-18: `RetryDecisions`, ограниченный `Retry-After`, хук `onRetry` | нет |

## Разработка

На хосте нет PHP/Composer — запускайте команды в Docker через образ `composer:2`:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer install
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer release-check
```

Или через Make:

```bash
make install
make build
make cs-fix
make test
make test-coverage
make mutation
make release-check
```

## Лицензия

[BSD-3-Clause](LICENSE.md)
