<?php

namespace EngageHub\Exceptions;

/** 5xx — thrown only after the client's own retries are exhausted. */
class ServerException extends EngageHubException {}
