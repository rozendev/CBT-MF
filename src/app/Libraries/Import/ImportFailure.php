<?php
namespace App\Libraries\Import;

final class ImportFailure extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = false, public readonly int $httpStatus = 400)
    {
        parent::__construct($message);
    }

    public static function from(\Throwable $error): self
    {
        if ($error instanceof self) return $error;
        $transient = in_array((int) $error->getCode(), [1205, 1213, 2002, 2006, 2013], true)
            || preg_match('/unable to connect|connection refused|server has gone away|lost connection|deadlock|lock wait timeout/i', $error->getMessage());
        log_message('error', 'Import worker: {type}, code {code}', ['type' => get_class($error), 'code' => $error->getCode()]);
        return new self($transient ? 'Database sementara tidak tersedia. Impor akan dicoba ulang.' : 'Impor gagal diproses. Periksa file atau hubungi administrator.', (bool) $transient, $transient ? 503 : 500);
    }
}
