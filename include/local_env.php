<?php
if (isset($_SERVER["REQUEST_URI"]) && substr($_SERVER["REQUEST_URI"], -13) == "local_env.php") {
  header("Location:./");
  exit;
}
