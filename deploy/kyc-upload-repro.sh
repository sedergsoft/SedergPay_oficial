#!/bin/bash
set -u
BASE="https://5-22-219-30.sslip.io"
DEMO="55676b51-b1a7-40a0-878e-80e82c6285fc"
ORIGIN="https://sedergsoft.github.io"

# JPEG valido de ~3 MB (basado en el de 1x1 pero inflado con relleno: misma firma)
echo "/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/9oADAMBAAIRAxEAPwD3+iiigD//2Q==" | base64 -d > /tmp/small.jpg
head -c 3145728 /dev/urandom >> /tmp/small.jpg
ls -la /tmp/small.jpg

echo ""
echo "=== Subida de 3 MB con Origin de GitHub Pages (como el navegador) ==="
curl -sS -m 60 -w '\nHTTP:%{http_code}\n' -X POST "$BASE/api/v1/kyc/documents" \
  -H "Origin: $ORIGIN" -H "X-User-Public-Id: $DEMO" \
  -F "file=@/tmp/small.jpg;type=image/jpeg" -F "document_type=identity"

echo ""
echo "=== Subida de 3 MB con POST > post_max_size (12 MB) ==="
head -c 12582912 /dev/urandom >> /tmp/big.jpg
curl -sS -m 60 -w '\nHTTP:%{http_code}\n' -X POST "$BASE/api/v1/kyc/documents" \
  -H "Origin: $ORIGIN" -H "X-User-Public-Id: $DEMO" \
  -F "file=@/tmp/big.jpg;type=image/jpeg" -F "document_type=identity"
