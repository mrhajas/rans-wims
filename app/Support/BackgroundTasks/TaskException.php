<?php

namespace App\Support\BackgroundTasks;

/** A problem whose words are safe and useful to show the person (any other exception is shown only as a general message and written to the log). */
class TaskException extends \RuntimeException
{
}
