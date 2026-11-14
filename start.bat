@echo off
chcp 65001 > nul
echo =======================================================
echo          ORACLE FOOT v2.0 - Lancement Unifié
echo               Football Intelligence Lab
echo =======================================================
echo.

:: 1. Vérification et Démarrage de MySQL (XAMPP)
netstat -ano | findstr :3306 > nul
if %errorlevel% neq 0 (
    echo [1/3] Demarrage de la base de donnees MySQL (XAMPP)...
    if exist "C:\xampp\mysql_start.bat" (
        start "MySQL Server" /min "C:\xampp\mysql_start.bat"
        timeout /t 3 /nobreak > nul
    ) else (
        echo [ATTENTION] Verifiez que votre serveur MySQL est bien lance sur le port 3306.
    )
) else (
    echo [1/3] Base de donnees MySQL deja active sur le port 3306.
)

:: 2. Démarrage du microservice Python FastAPI
echo [2/3] Demarrage du microservice Python IA (FastAPI sur 127.0.0.1:8001)...
start "ORACLE FOOT - Moteur Statistique Python" cmd /k "cd ai-service && .\venv\Scripts\uvicorn app.main:app --host 127.0.0.1 --port 8001"

timeout /t 2 /nobreak > nul

:: 3. Démarrage de l'application Web Laravel
echo [3/3] Demarrage du serveur Web Laravel (127.0.0.1:8000)...
start "ORACLE FOOT - Serveur Web Laravel" cmd /k "php artisan serve --host=127.0.0.1 --port=8000"

timeout /t 2 /nobreak > nul

echo.
echo =======================================================
echo  Services operationnels :
echo    - Application Web      : http://127.0.0.1:8000
echo    - Moteur IA FastAPI    : http://127.0.0.1:8001/v1/health
echo    - Documentation API    : http://127.0.0.1:8001/docs
echo =======================================================
echo.

:: Ouverture automatique dans le navigateur par défaut
start http://127.0.0.1:8000

echo Fermez cette fenetre quand vous le souhaitez (les services tournent en arriere-plan).
pause
