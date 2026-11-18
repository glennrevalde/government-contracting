<?php
declare(strict_types=1);

/** The Office file is protected with a password: the quick view cannot read it (the page view of LibreOffice cannot either) */
final class OfficeLockedException extends RuntimeException
{
}
