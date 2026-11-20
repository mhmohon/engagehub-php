<?php

namespace EngageHub\Exceptions;

/** No HTTP response at all (DNS, connect, timeout) — retried before thrown. */
class NetworkException extends EngageHubException {}
