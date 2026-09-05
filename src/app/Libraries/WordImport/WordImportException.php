<?php

namespace App\Libraries\WordImport;

/**
 * Kegagalan impor yang pesannya memang ditulis untuk dibaca guru, jadi aman
 * ditampilkan apa adanya di respons -- beda dari exception lain (I/O, driver
 * database) yang isinya detail internal dan cuma boleh masuk log.
 */
class WordImportException extends \RuntimeException
{
}
