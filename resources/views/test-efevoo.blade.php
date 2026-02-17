// resources/views/test-efevoo.blade.php (opcional)
<!DOCTYPE html>
<html>
<head>
    <title>Prueba EfevooPay</title>
</head>
<body>
    <h1>Prueba EfevooPay</h1>
    
    <form action="{{ route('test.efevoo.tokenize') }}" method="POST">
        @csrf
        <div>
            <label>Número de tarjeta:</label>
            <input type="text" name="card_number" value="4111111111111111" required>
        </div>
        <div>
            <label>Expiración (MMYY):</label>
            <input type="text" name="expiration" value="1230" required>
        </div>
        <div>
            <label>Titular:</label>
            <input type="text" name="card_holder" value="TEST USER" required>
        </div>
        <button type="submit">Probar Tokenización ($1.50 MXN)</button>
    </form>
    
    <div style="margin-top: 20px;">
        <a href="{{ route('test.efevoo') }}">Probar Conexión API</a>
    </div>
    
    @if(session('result'))
        <pre style="background: #f4f4f4; padding: 10px; margin-top: 20px;">
            {{ json_encode(session('result'), JSON_PRETTY_PRINT) }}
        </pre>
    @endif
</body>
</html>