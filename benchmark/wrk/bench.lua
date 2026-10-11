-- The request of the scenario, set by the SCENARIO environment variable
-- Prints the summary as one JSON line, prefixed with BENCH_JSON

local scenario = os.getenv("SCENARIO") or "hello"

if scenario == "json" then
    -- a browser-like request, tests the conversion of headers, query and cookies
    wrk.path = "/json?page=2&sort=name&tags[]=a&tags[]=b"
    wrk.headers["User-Agent"] = "Mozilla/5.0 (X11; Linux x86_64; rv:128.0) Gecko/20100101 Firefox/128.0"
    wrk.headers["Accept"] = "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8"
    wrk.headers["Accept-Language"] = "en-US,en;q=0.5"
    wrk.headers["Accept-Encoding"] = "gzip, deflate, br"
    wrk.headers["Referer"] = "https://example.com/users"
    wrk.headers["Cookie"] = "session=0123456789abcdef; theme=dark"
elseif scenario == "form" then
    wrk.method = "POST"
    wrk.path = "/form"
    wrk.body = "name=Ann&email=ann%40example.com&tags%5B%5D=a&tags%5B%5D=b"
    wrk.headers["Content-Type"] = "application/x-www-form-urlencoded"
elseif scenario == "close" then
    -- a new connection per request
    wrk.headers["Connection"] = "close"
end

function done(summary, latency, requests)
    io.write(string.format(
        'BENCH_JSON {"requests":%d,"duration_us":%d,"rps":%.1f,"p50_us":%d,"p90_us":%d,"p99_us":%d,"max_us":%d,'
            .. '"errors":{"connect":%d,"read":%d,"write":%d,"status":%d,"timeout":%d}}\n',
        summary.requests, summary.duration, summary.requests / summary.duration * 1e6,
        latency:percentile(50), latency:percentile(90), latency:percentile(99), latency.max,
        summary.errors.connect, summary.errors.read, summary.errors.write, summary.errors.status, summary.errors.timeout
    ))
end
