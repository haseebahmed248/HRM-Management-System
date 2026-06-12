<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') - {{ config('app.name', 'AfriPay HR') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet" />
    <style>
        :root {
            --primary: #3b82f6;
            --primary-light: rgba(59, 130, 246, 0.1);
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 50%, #f5f5f4 100%);
            position: relative;
            overflow: hidden;
        }

        /* Subtle dot pattern overlay */
        body::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image: radial-gradient(circle at 30% 70%, var(--primary) 1px, transparent 1px);
            background-size: 80px 80px;
            opacity: 0.4;
            pointer-events: none;
        }

        .container {
            width: 100%;
            max-width: 420px;
            position: relative;
            z-index: 1;
        }

        /* Logo section */
        .logo-wrapper {
            text-align: center;
            margin-bottom: 2rem;
        }

        .logo {
            display: inline-block;
            max-width: 180px;
            height: auto;
        }

        .logo img {
            width: 100%;
            height: auto;
            object-fit: contain;
        }

        /* Card with corner accents */
        .card-wrapper {
            position: relative;
        }

        .corner-accent {
            position: absolute;
            width: 1.5rem;
            height: 1.5rem;
            border-color: var(--primary);
            border-style: solid;
            border-width: 0;
        }

        .corner-accent.top-left {
            top: -0.75rem;
            left: -0.75rem;
            border-top-width: 2px;
            border-left-width: 2px;
            border-top-left-radius: 0.375rem;
        }

        .corner-accent.bottom-right {
            bottom: -0.75rem;
            right: -0.75rem;
            border-bottom-width: 2px;
            border-right-width: 2px;
            border-bottom-right-radius: 0.375rem;
        }

        .card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 0.5rem;
            padding: 2.5rem 2rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            text-align: center;
        }

        /* Error icon circle */
        .icon-wrapper {
            width: 4rem;
            height: 4rem;
            margin: 0 auto 1.5rem;
            background: var(--primary-light);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .icon-wrapper svg {
            width: 2rem;
            height: 2rem;
            color: var(--primary);
        }

        /* Error code */
        .error-code {
            font-size: 4rem;
            font-weight: 700;
            color: #111827;
            line-height: 1;
            margin-bottom: 0.5rem;
            letter-spacing: -0.02em;
        }

        /* Decorative line */
        .divider {
            width: 3rem;
            height: 2px;
            background: var(--primary);
            margin: 0 auto 1rem;
        }

        .error-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #111827;
            margin-bottom: 0.75rem;
            letter-spacing: 0.01em;
        }

        .error-message {
            color: #4b5563;
            font-size: 0.875rem;
            line-height: 1.6;
            margin-bottom: 2rem;
        }

        /* Buttons */
        .btn-group {
            display: flex;
            gap: 0.75rem;
            justify-content: center;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.625rem 1.25rem;
            font-size: 0.875rem;
            font-weight: 500;
            text-decoration: none;
            border-radius: 0.375rem;
            transition: all 0.15s ease;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
            border: 1px solid var(--primary);
        }

        .btn-primary:hover {
            background: #2563eb;
            border-color: #2563eb;
        }

        .btn-secondary {
            background: white;
            color: #374151;
            border: 1px solid #d1d5db;
        }

        .btn-secondary:hover {
            background: #f9fafb;
            border-color: #9ca3af;
        }

        .btn svg {
            width: 1rem;
            height: 1rem;
        }

        /* Footer */
        .footer {
            text-align: center;
            margin-top: 1.5rem;
        }

        .footer-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 0.375rem;
            padding: 0.5rem 1rem;
            font-size: 0.75rem;
            color: #6b7280;
        }

        /* Responsive */
        @media (max-width: 480px) {
            .card {
                padding: 2rem 1.5rem;
            }

            .error-code {
                font-size: 3rem;
            }

            .btn-group {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Logo -->
        <div class="logo-wrapper">
            <div class="logo">
                <img src="{{ asset('images/logos/Afripay HR Logo_page-0001.jpg') }}" alt="{{ config('app.name', 'AfriPay HR') }}">
            </div>
        </div>

        <!-- Card with corner accents -->
        <div class="card-wrapper">
            <div class="corner-accent top-left"></div>
            <div class="corner-accent bottom-right"></div>

            <div class="card">
                <!-- Icon -->
                <div class="icon-wrapper">
                    @yield('icon')
                </div>

                <!-- Error code -->
                <div class="error-code">@yield('code')</div>

                <!-- Divider -->
                <div class="divider"></div>

                <!-- Title -->
                <h1 class="error-title">@yield('title')</h1>

                <!-- Message -->
                <p class="error-message">@yield('message')</p>

                <!-- Buttons -->
                <div class="btn-group">
                    <a href="{{ url('/') }}" class="btn btn-primary">
                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                        </svg>
                        Go Home
                    </a>
                    <a href="javascript:history.back()" class="btn btn-secondary">
                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                        </svg>
                        Go Back
                    </a>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div class="footer">
            <div class="footer-badge">
                {{ config('app.name', 'AfriPay HR') }} • A product of Aromerc & Co. Ltd
            </div>
        </div>
    </div>
</body>
</html>
