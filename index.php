<?php declare(strict_types=1); 
    if (isset ($_GET["search"]) and ($_GET["search"] == "modules/utilisateurs/login.php"))
     {
        include("modules/utilisateurs/login.php");
     }
     elseif(isset ($_GET["search"]) and ($_GET["search"] == "modules/utilisateurs/register.php"))
     {
        include("modules/utilisateurs/register.php");
     }
     elseif(isset ($_GET["search"]) and ($_GET["search"] == "modules/utilisateurs/reset.php"))
    {
        include("modules/utilisateurs/reset.php");
    }
     else
     {
         include("modules/utilisateurs/login.php");
     }
 ?>