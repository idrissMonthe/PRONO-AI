@echo off
echo =======================================================
echo          ORACLE FOOT v2.0 - Lancement des Services
echo               Football Intelligence Lab
echo =======================================================
echo.
echo [1/2] Demarrage du microservice Python IA (FastAPI sur 127.0.0.1:8001)...
start "ORACLE FOOT - Moteur Statistique Python" cmd /k "cd ai-service && .\venv\Scripts\uvicorn app.main:app --host 127.0.0.1 --port 8001"

timeout /t 2 /nobreak > nul

echo [2/2] Demarrage de l'application Web Laravel (127.0.0.1:8000)...
start "ORACLE FOOT - Serveur Web Laravel" cmd /k "php artisan serve --host=127.0.0.1 --port=8000"

echo.
echo Application accessible sur : http://127.0.0.1:8000
echo Documentation de l'API IA : http://127.0.0.1:8001/docs
echo.
pause

