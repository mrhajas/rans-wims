<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <title>Project(s) / Warehouse(s) Inventory Management Solution</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://fonts.googleapis.com/css?family=Mukta:200,600" rel="stylesheet" type="text/css">
  <style>
    html,
    body {
      margin: 0;
      height: 100vh;
      color: #636b6f;
      font-weight: 200;
      background-color: #fff;
      font-family: 'Mukta', sans-serif;
    }

    .full-height {
      height: 100vh;
    }

    .flex-center {
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .content {
      margin: auto;
      text-align: center;
    }

    .title {
      font-size: 84px;
      margin-top: 24px;
      line-height: 42px;
      margin-bottom: 24px;
    }

    .title.message {
      color: #333;
      font-size: 16px;
      line-height: 1.6;
      padding: 8px 16px;
      border-radius: 5px;
      font-weight: normal;
      background: #f5f6f7;
      font-family: 'Courier New', Courier, monospace;
    }

    .title.small {
      color: #aababa;
      font-size: 24px;
    }

    .by {
      padding: 24px;
      color: #aababa;
      font-size: 18px;
    }

    .links>a {
      color: #636b6f;
      padding: 0 24px;
      font-size: 15px;
      font-weight: 600;
      letter-spacing: .1rem;
      text-decoration: none;
      text-transform: uppercase;
    }

    .m-b-md {
      margin-bottom: 30px;
    }

  </style>
</head>

<body>
  <div class="flex-center full-height">
    <div class="content">
      <div class="title m-b-md">
        RANS WIMS
      </div>
      <div class="title small">
        RANS Workflow Integrated Management System
      </div>

      @if (session('message'))
        <div class="title message">
          {!! nl2br(session('message')) !!}
        </div>
      @else
        <div class="links">
          <a href="{{ url('/') }}">Go to Dashboard</a>
        </div>
        <div class="by">
          by <strong>RANS WIMS</strong>
        </div>
      @endif
    </div>
  </div>
</body>

</html>
