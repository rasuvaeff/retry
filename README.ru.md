# Расуваефф/повторить попытку
[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/retry/v)](https://packagist.org/packages/rasuvaeff/retry)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/retry/downloads)](https://packagist.org/packages/rasuvaeff/retry)
[![Build](https://github.com/rasuvaeff/retry/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/retry/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/retry/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/retry/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/retry/actions/workflows/static-analysis.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/retry/php)](https://packagist.org/packages/rasuvaeff/retry)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)
Повторная попытка с первым замыканием с фиксированной/экспоненциальной задержкой, полным/аддитивным джиттером, временными бюджетами
, тестируемыми интерфейсами часов/спящего режима/рандомайзера, перехватчиками наблюдаемости и
 декоратором HTTP-клиента PSR-18, поддерживающим `Retry-After`.

 > Используете помощника по программированию с искусственным интеллектом? [llms.txt](llms.txt) содержит компактную ссылку на API, которой вы можете поделиться с моделью. @@ЛИНИЯ@@
## Требования
- PHP 8.3+
 - `psr/lock` ^1.0
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
Именованные фабрики политик возвращают готовый к использованию конструктор:

```php
Retry::fixed(delayMs: 500, maxAttempts: 3);
Retry::exponential(maxAttempts: 3);
Retry::immediate(maxAttempts: 3);
```
Настройте построитель с помощью методов `with*`:

```php
Retry::new()->withExponential(baseMs: 100, multiplier: 2.0, capMs: 30_000);
Retry::new()->withFixed(delayMs: 500);
Retry::new()->withImmediate();
Retry::new()->withClock(new SystemClock());
```
Каждый метод фабрики и сборки — это реальный, статически анализируемый метод — в
 нет магических псевдонимов `__call`/`__callStatic`. @@ЛИНИЯ@@
### Продолжительность
Каждая миллисекундная точка входа `int` имеет аналог `rasuvaeff/duration`, поэтому
 вы можете выражать задержки и бюджеты как типобезопасные объекты значений `Duration` вместо
 неоднозначных целых чисел. API `int`-ms не изменился; Варианты длительности
 являются чисто аддитивными. @@ЛИНИЯ@@
```php
use Rasuvaeff\Duration\Duration;

Retry::fixedFor(delay: Duration::seconds(1), maxAttempts: 3);
Retry::exponentialFor(base: Duration::millis(100), cap: Duration::seconds(30));

Retry::new()
    ->withExponentialFor(base: Duration::millis(100), cap: Duration::seconds(30))
    ->stopAfter(budget: Duration::seconds(10));

RetryPolicy::fixedFor(delay: Duration::seconds(1));
```
`AttemptRecord` и `Http\HttpAttemptRecord` предоставляют одни и те же значения на выходе
: `delay(): ?Duration` (нуль в записи терминала) и
 `elapsed(): Duration`, наряду с существующими полями `delayMs`/`elapsedMs`.

 По умолчанию повторная попытка запускается при любом `\Exception`. `\Error` (например, `\TypeError`, ошибки утверждения
) **не** повторяется — укажите это явно с помощью
 `retryOn(\Error::class)`. `retryOn()` **заменяет** список классов (так что вы можете
 сузить список по умолчанию), а `retryIf()` и `stopIf()` накладывают дополнительные предикаты. @@ЛИНИЯ@@
### Джиттер
`jitter()` выбирает стратегию с помощью перечисления `Jitter\JitterMode`. `factor`
 применяется только к `Additive` (он игнорируется для `Full` и `None`). @@ЛИНИЯ@@
```php
use Rasuvaeff\Retry\Jitter\JitterMode;

Retry::new()->jitter(factor: 0.2, mode: JitterMode::Additive); // equal jitter: [(1-factor)·delay, delay]
Retry::new()->jitter(mode: JitterMode::Full);                  // random in [0, delay]
Retry::new()->jitter(mode: JitterMode::None);                  // no jitter
```
«Аддитивный» — это «равный джиттер»: он расширяет задержку только **вниз**, поэтому значение джиттера
 никогда не превышает задержку отсрочки, а «capMs» остается жестким потолком.
 Или внедрите стратегию напрямую с помощью `withJitter(new Jitter\FullJitter())`. @@ЛИНИЯ@@
### Повторная попытка вернуть значение
`retryIfResult()` повторяет попытку, когда операция завершается успешно, но значение
 неприемлемо (исключение не требуется). При исчерпании отклоненного значения
 можно получить через `RetryExhausted::lastException` (`UnacceptableResult`). @@ЛИНИЯ@@
```php
$value = Retry::new()
    ->maxAttempts(5)
    ->retryIfResult(fn(Response $r): bool => $r->status === 'pending')
    ->run(fn(): Response => $api->poll());
```
### Бюджет времени
`stopAfterMs()` ограничивает общее время, затрачиваемое на повторную попытку. Попытка 1 всегда выполняется
; последующая попытка пропускается, если `elapsed + nextDelay` превысит бюджет
, поэтому неиспользованное время ожидания не добавляется. @@ЛИНИЯ@@
```php
Retry::new()
    ->maxAttempts(maxAttempts: 10)
    ->withExponential(baseMs: 200)
    ->stopAfterMs(budgetMs: 5_000)
    ->run(fn(): string => flakyOperation());
```
Каждая запись AttemptRecord в RetryExhausted::history содержит elapsedMs,
 миллисекунды между запуском run() и этой попыткой. Обратные вызовы `onRetry` получают
 полную `AttemptRecord`; Обратные вызовы onExhausted получают RetryExhausted.
 `RetryExhausted::reason` — это `ExhaustionReason` (`MaxAttempts` или `TimeBudget`)
, объясняющий, почему цикл завершился. Терминал «AttemptRecord» имеет значение «delayMs»
 «null» (за ним не следует сон). @@ЛИНИЯ@@
### Интеграция результатов
Этот пакет не зависит от типа результата. Чтобы получить `Result<T, RetryExhausted>`
 без исключений, оберните `run()` своей собственной библиотекой результатов. С помощью
 `rasuvaeff/result`:

```php
use Rasuvaeff\Result\Result;

$result = Result::fromThrowable(
    fn() => Retry::new()->maxAttempts(3)->run(fn(): string => flakyOperation()),
);

$value = $result->unwrapOr('fallback');
```
`Result::fromThrowable()` перехватывает любой `Throwable` из `run()` (включая
`RetryExhausted`), поэтому канал ошибок является `Throwable`, если вы не сузите его с помощью
 `mapErr()`. @@ЛИНИЯ@@
### Декоратор ПСР-18
`RetryingHttpClient` украшает любой клиент PSR-18. `retryOnResponse` решает, какие ответы
 повторяются; `Http\RetryDecisions` поставляет готовые предикаты. @@ЛИНИЯ@@
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
И `retryOnResponse`, и `retryOnException` получают `RequestInterface` в качестве второго аргумента
, поэтому повторные попытки могут быть ограничены методом запроса:

```php
retryOnResponse: fn(ResponseInterface $r, RequestInterface $req): bool
    => $req->getMethod() === 'GET' && $r->getStatusCode() >= 500,
```
Готовые предикаты ответа:

 | Предикат | Повторные попытки |
 |---|---|
 | `RetryDecisions::serverErrors()` | Любой 5хх (500–599). |
 | `RetryDecisions::rateLimited()` | только 429. |
 | `RetryDecisions::transient()` | 408, 425, 429, 500, 502, 503, 504. |
 | `RetryDecisions::onlyIdempotent($inner)` | Обертывает `$inner`; повторяет **только** идемпотентные методы (GET, HEAD, PUT, DELETE, OPTIONS, TRACE). |

 Аргументы конструктора, выходящие за пределы `inner`/`policy`/`retryOnResponse`:

 | Аргумент | По умолчанию | Эффект |
 |---|---|---|
 | `часы` | `Часы\SystemClock` | Часы PSR-20 для анализа HTTP-даты и определения бюджета «Повторить-после». |
 | `respectRetryAfter` | `правда` | Уважайте заголовок Retry-After сервера. |
 | `maxRetryAfterMs` | `300_000` | Ограничение задержки повторной попытки; `null` отключает ограничение. |
 | `бюджетМс` | `ноль` | Общий бюджет настенных часов; повторная попытка пропускается, если `elapsed + Delay` превышает его. |
 | `retryOnException` | `ноль` | Предикат `fn(ClientExceptionInterface, RequestInterface): bool`; `null` повторяет каждое транспортное исключение. Несовпадающие исключения создаются повторно как есть. |
 | `throwOnExhausted` | `ложь` | Если задано значение true, при исчерпании выдается `Http\HttpRetryExhausted` (переносящий историю) вместо возврата последнего ответа/повторного создания последнего транспортного исключения. |
 | `onRetry` | `[]` | Обратные вызовы `fn(HttpAttemptRecord): void` запускаются перед каждой повторной попыткой сна. |
 | `onExhausted` | `[]` | Обратные вызовы `fn(HttpRetryExhausted): void` запускаются при каждом исчерпании ресурсов (`maxAttempts` или `budgetMs`); аргумент содержит «попытки» и полную «историю». |

 Бюджет одной попытки охватывает как повторяющиеся ответы, так и исключения
 транспорта PSR-18, поэтому внутренний клиент вызывается не чаще, чем `maxAttempts`. Когда повторяемый ответ
 содержит действительный заголовок Retry-After, эта задержка заменяет настроенную отсрочку
 (дельта-секунды или IMF-fixdate; устаревшие формы даты RFC 850 и
 asctime игнорируются). Задержка сервера не вызывает дрожания и ограничивается
 параметром maxRetryAfterMs. Исключения транспорта всегда возвращаются к отсрочке (
 `Retry-After` недоступен). Передайте `respectRetryAfter: false`, чтобы отключить обработку заголовка
.

 `Http\HttpRetryExhausted` реализует `Psr\Http\Client\ClientExceptionInterface`,
, поэтому он остается в рамках контракта PSR-18 — код, который перехватывает `ClientExceptionInterface`
, все равно перехватывает его.

 Каждый `HttpAttemptRecord` содержит `attempt`, `delayMs`, `elapsedMs` и ровно
 одно из `response`/`Exception` (никогда оба, никогда ни то, ни другое). Добавьте
 `Clock\FakeClock`, чтобы сделать задержки Retry-After и бюджетные сроки детерминированными в тестах
. @@ЛИНИЯ@@
### Публичный API
| Класс | Описание |
 |---|---|
 | `Повторить` | Неизменяемый построитель повторов и средство закрытия. |
 | `RetryPolicy` | Многоразовый объект политики для декораторов. |
 | `RetryPolicyInterface` | Контракт политики только для чтения. |
 | `ПовторитьИсчерпано` | Исключение с попытками, последним исключением, историей и «причиной». |
 | `Причина истощения` | Перечисление: MaxAttempts или TimeBudget. |
 | `Попытка записи` | Одна неудачная попытка: номер попытки, `delayMs` (нуль при терминале), истекшее время и исключение. |
 | `НеприемлемыйРезультат` | Исключение, несущее значение предиката `retryIfResult()`, отклонено. |
 | `BackoffStrategy\BackoffStrategyInterface` | Контракт на отсрочку возврата. |
 | `BackoffStrategy\FixedBackoff` | Постоянная задержка. |
 | `BackoffStrategy\ExponentialBackoff` | Экспоненциальная задержка с ограничением. |
 | `BackoffStrategy\ImmediateBackoff` | Нулевая задержка. |
 | `Джиттер\ДжиттерИнтерфейс` | Джиттер-контракт. |
 | `Джиттер\ДжиттерРежим` | Перечисление, выбирающее стратегию для `jitter()`: `Additive`, `Full`, `None`. |
 | `Джиттер\FullJitter` | Случайная задержка между нулевой и вычисленной задержкой. |
 | `Джиттер\АддитивДжиттер` | Равный джиттер: распределяет задержку вниз, но не выше нее. |
 | `Джиттер\NoJitter` | Оставляет задержку без изменений. |
 | `Часы\SystemClock` | Системные часы PSR-20. |
 | `Часы\FakeClock` | Изменяемые часы PSR-20 для тестов с помощью advanceMs(). |
 | `Спящий\СпящийИнтерфейс` | Контракт на сон. |
 | `Спящий\SystemSleeper` | реализация `usleep()`. |
 | `Спящий\FakeSleeper` | Тестирование задержек записи в спящем режиме. |
 | `Рандомайзер\РандомайзерИнтерфейс` | Контракт рандомизатора с плавающей запятой. |
 | `Рандомайзер\СистемныйРандомайзер` | Рандомизатор времени выполнения. |
 | `Рандомайзер\FixedRandomizer` | Детерминированный тест-рандомизатор. |
 | `Http\RetryingHttpClient` | Декоратор повтора PSR-18 с поддержкой Retry-After. |
 | `Http\RetryDecisions` | Готовые предикаты ответа + обертка onlyIdempotent(). |
 | `Http\HttpAttemptRecord` | Одна попытка HTTP: попытка, задержка, истекшее время и «ответ» xor «исключение». |
 | `Http\HttpRetryExhausted` | Дескриптор исчерпания, передаваемый каждому хуку onExhausted и вызываемый, когда установлен throwOnExhausted; ClientExceptionInterface, содержащий попытки и историю. |
 | `Http\RetryAfterParser` | Разбирает `Retry-After` в миллисекундах с помощью часов PSR-20. | @@ЛИНИЯ@@
## Работники с длительным сроком службы (RoadRunner, Swoole, FrankenPHP)
Пакет можно безопасно повторно использовать в запросах в долгоживущем воркере: каждый класс
 доступен только для окончательного чтения без глобального или статического изменяемого состояния, а SystemClock
 пересчитывает настенные часы при каждом вызове now() (он никогда не зависает). Создайте Retry
 или RetryingHttpClient один раз и поделитесь им.

 Предостережение: **отсрочка сна**. `SystemSleeper::sleepMs()` вызывает `usleep()`,
, который **блокирует текущего работника** на всю задержку. Рабочий обслуживает один запрос
 за раз, поэтому ожидающая повторная попытка — экспоненциальная отсрочка до `capMs`
 (по умолчанию 30 с) или серверная `Retry-After` до `maxRetryAfterMs` (**по умолчанию
 300_000 = 5 минут**) — связывает этого рабочего на время. При использовании фиксированного рабочего пула
 несколько запросов, повторяющихся с длительными задержками, могут привести к истощению пула и снижению пропускной способности
.

 Рекомендации:

 | рычаг | Действие |
 |---|---|
 | Ограничить задержки, вызванные сервером | Уменьшите значение `maxRetryAfterMs` (например, на несколько секунд), чтобы враждебный/большой `Retry-After` не мог закрепить работника; сохраняйте скромные капитализации и бюджеты. |
 | Убрать длинные повторы с горячего пути | Выполняйте повторные попытки со значимой отсрочкой выполнения из очереди/работника заданий RoadRunner, а не из исполнителя синхронных запросов. |
 | Кооперативный сон | Внедрите неблокирующий `SleeperInterface` через `Retry::withSleeper()` (ядро) или спящий режим политики (HTTP), когда ваша среда выполнения предлагает совместное планирование (например, сон сопрограммы Swoole). |

 `RetryingHttpClient` украшает ваш **исходящий** клиент PSR-18; это не связано с
 обработкой сервером входящих запросов PSR-7/PSR-15. @@ЛИНИЯ@@
## Безопасность
Этот пакет вызывает только замыкания и клиенты PSR-18, предоставляемые приложением.
 Он не проверяет учетные данные, URL-адреса, тела запросов или тела ответов. Хуки
 получают исключения и метаданные времени; не регистрируйте секреты из сообщений об исключениях
 без редактирования на уровне приложения. Значения `Retry-After` рассматриваются как непрозрачные подсказки времени
 и не используются для создания URL-адресов или запросов.

 **Idempotency.** `RetryingHttpClient` будет повторять любой запрос, который вы ему дадите,
, включая неидемпотентные методы (`POST`, `PATCH`), которые могут вызвать повторяющиеся побочные эффекты
, если сервер обработал первый запрос до того, как не удалось получить ответ
. Gate повторяет попытку обращения к идемпотентным методам с помощью
 `RetryDecisions::onlyIdempotent(...)` или вашего собственного предиката, учитывающего запросы, если только
 конечная точка не является безопасной для повторения (например, защищена ключом идемпотентности). @@ЛИНИЯ@@
## Примеры
См. [examples/](examples/) для работоспособных сценариев.

 | Скрипт | Шоу | Нужен сервер? |
 |---|---|---|
 | `basic.php` | Повторная попытка закрытия с ложным шпалом и фиксированной задержкой | Нет |
 | `time_budget.php` | `stopAfterMs` с FakeClock + истекшее время в истории | Нет |
 | `result_retry.php` | `retryIfResult` для возвращаемого значения; `UnacceptableResult` при исчерпании ресурсов | Нет |
 | `retry_after.php` | Декоратор PSR-18: RetryDecisions, ограниченный Retry-After, крючок onRetry | Нет | @@ЛИНИЯ@@
## Разработка
На хосте нет PHP/Composer. Запускайте команды в Docker через образ `composer:2`:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer install
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer release-check
```
Или с помощью Make:

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
[BSD-3-пункт](LICENSE.md)
