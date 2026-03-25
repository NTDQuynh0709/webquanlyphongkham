<?php
require __DIR__ . '/../vendor/autoload.php';

use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverExpectedCondition;
use Facebook\WebDriver\WebDriverWait;

class LoginCsvTester
{
    private RemoteWebDriver $driver;
    private string $seleniumHost = 'http://localhost:4444';
    private string $loginUrl = 'http://localhost/webquanlyphongkham/login.php';

    public function setUp(): void
    {
        $this->driver = RemoteWebDriver::create(
            $this->seleniumHost,
            DesiredCapabilities::chrome()
        );
        $this->driver->manage()->window()->maximize();
    }

    public function tearDown(): void
    {
        if (isset($this->driver)) {
            $this->driver->quit();
        }
    }

    private function openLoginPage(): void
    {
        $this->driver->get($this->loginUrl);

        $wait = new WebDriverWait($this->driver, 10);
        $wait->until(
            WebDriverExpectedCondition::presenceOfElementLocated(
                WebDriverBy::id('loginForm')
            )
        );
    }

    private function setInputByName(string $name, string $value): void
    {
        $el = $this->driver->findElement(WebDriverBy::name($name));
        $el->clear();
        if ($value !== '') {
            $el->sendKeys($value);
        }
    }

    private function submitLogin(): void
    {
        $this->driver->findElement(
            WebDriverBy::cssSelector('#loginForm button[type="submit"]')
        )->click();
    }

    private function waitForRedirectContains(string $expectedUrlPart, int $timeout = 10): void
    {
        $wait = new WebDriverWait($this->driver, $timeout);
        $wait->until(function () use ($expectedUrlPart) {
            return str_contains($this->driver->getCurrentURL(), $expectedUrlPart);
        });
    }

    private function waitForErrorVisible(int $timeout = 10): void
    {
        $wait = new WebDriverWait($this->driver, $timeout);
        $wait->until(function () {
            $el = $this->driver->findElement(WebDriverBy::id('errorMessage'));
            $class = $el->getAttribute('class') ?? '';
            $text = trim($el->getText());
            return !str_contains($class, 'hidden') && $text !== '';
        });
    }

    private function getErrorText(): string
    {
        return trim($this->driver->findElement(WebDriverBy::id('errorMessage'))->getText());
    }

    private function getValidationMessage(string $fieldName): string
    {
        $el = $this->driver->findElement(WebDriverBy::name($fieldName));
        return (string)$this->driver->executeScript('return arguments[0].validationMessage;', [$el]);
    }

    private function getCurrentUrl(): string
    {
        return $this->driver->getCurrentURL();
    }

    private function readCsv(string $file): array
    {
        $rows = [];

        if (!file_exists($file)) {
            throw new Exception("Không tìm thấy file CSV: $file");
        }

        $handle = fopen($file, 'r');
        if ($handle === false) {
            throw new Exception("Không mở được file CSV: $file");
        }

        $headers = fgetcsv($handle);
        if (!$headers) {
            fclose($handle);
            throw new Exception("CSV không có header");
        }

        while (($data = fgetcsv($handle)) !== false) {
            if (count($data) === 0) {
                continue;
            }

            $row = [];
            foreach ($headers as $i => $header) {
                $row[trim($header)] = $data[$i] ?? '';
            }
            $rows[] = $row;
        }

        fclose($handle);
        return $rows;
    }

    public function runFromCsv(string $csvPath): void
    {
        $cases = $this->readCsv($csvPath);

        foreach ($cases as $case) {
            $tcId = $case['tc_id'] ?? '';
            $desc = $case['mo_ta'] ?? '';
            $username = $case['username'] ?? '';
            $password = $case['password'] ?? '';
            $expectedType = $case['expected_type'] ?? '';
            $expectedValue = $case['expected_value'] ?? '';

            try {
                $this->openLoginPage();
                $this->setInputByName('username', $username);
                $this->setInputByName('password', $password);
                $this->submitLogin();

                if ($expectedType === 'redirect') {
                    $this->waitForRedirectContains($expectedValue, 10);
                    echo "{$tcId} PASS - {$desc}\n";
                } elseif ($expectedType === 'error') {
                    $this->waitForErrorVisible(10);
                    $actualError = $this->getErrorText();

                    if (!str_contains($actualError, $expectedValue)) {
                        throw new Exception("Sai lỗi mong đợi. Actual: {$actualError}");
                    }

                    echo "{$tcId} PASS - {$desc}\n";
                } elseif ($expectedType === 'browser_validation') {
                    $fieldName = ($username === '') ? 'username' : 'password';
                    $validation = $this->getValidationMessage($fieldName);
                    $currentUrl = $this->getCurrentUrl();

                    if ($validation === '') {
                        throw new Exception("Không thấy validation message của browser");
                    }

                    if (!str_contains($currentUrl, 'login.php')) {
                        throw new Exception("Form đã submit khỏi login page, không đúng mong đợi");
                    }

                    echo "{$tcId} PASS - {$desc}\n";
                } else {
                    throw new Exception("expected_type không hợp lệ: {$expectedType}");
                }

            } catch (Throwable $e) {
                echo "{$tcId} FAIL - {$desc} - " . $e->getMessage() . "\n";
            }
        }
    }
}

$tester = new LoginCsvTester();

try {
    $tester->setUp();
    $tester->runFromCsv(__DIR__ . '/login_test_data.csv');
} catch (Throwable $e) {
    echo 'TEST ERROR: ' . $e->getMessage() . PHP_EOL;
} finally {
    $tester->tearDown();
}