<!-- resources/views/app.blade.php -->
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Habit Tracker</title>

    <!-- Vite -->
    @vite(['resources/js/app.js', 'resources/css/app.css'])
</head>
<body class="bg-gray-50 text-gray-900">
    <!-- Vue SPA mount point -->
    <div id="app"></div>
</body>
</html>