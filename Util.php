<?php

function _log($level, $message) {
    openlog("StampsAPILog", LOG_CONS | LOG_NDELAY | LOG_PID, LOG_USER | LOG_PERROR);
    syslog($level, $message);
    closelog();
}

?>