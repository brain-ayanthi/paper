<?php

namespace App\Actions\Installer;

/**
 * Writes selected key/value pairs into the project's .env file.
 * Keys that already exist are updated in place; new keys are appended.
 */
class WriteEnvFile
{
    /** @param array<string, string|int|bool|null> $values */
    public function __invoke(array $values): void
    {
        $path = base_path('.env');
        $contents = file_exists($path) ? file_get_contents($path) : '';

        foreach ($values as $key => $value) {
            $line = $key.'='.$this->quote((string) $value);

            if (preg_match("/^{$key}=.*/m", $contents)) {
                $contents = preg_replace("/^{$key}=.*/m", $line, $contents);
            } else {
                $contents .= "\n".$line;
            }
        }

        if (file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new \RuntimeException('Could not save environment configuration.');
        }
        @chmod($path, 0600);
    }

    private function quote(string $value): string
    {
        // Strip line breaks and NULs BEFORE quoting (SEC #21 / defect #78).
        //
        // `addslashes()` escapes quotes and backslashes but leaves newlines
        // intact, and .env is line-oriented — so a DB password of
        // "secret\nAPP_DEBUG=true" wrote a second, entirely attacker-chosen
        // setting into the file. During installation nothing is authenticated
        // yet, which makes the installer a particularly poor place to accept
        // arbitrary config. A CR/LF in any of these values is never legitimate,
        // so removing it costs nothing.
        $value = str_replace(["\r", "\n", "\0"], '', $value);

        // Quote when the value contains whitespace or shell-special chars.
        return preg_match('/[\s#"\'\\\\]/', $value) ? '"'.addslashes($value).'"' : $value;
    }
}
