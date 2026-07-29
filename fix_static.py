import os

directory = 'src/public/static'
old_text = """        try {
            const body = { test_id: EXAM_CONFIG.testId };
            if (CSRF_NAME) body[CSRF_NAME] = CSRF_HASH;"""

new_text = """        try {
            try {
                const csrfRes = await fetch(API + '/health', { credentials: 'same-origin' });
                const csrfToken = csrfRes.headers.get('X-CSRF-TOKEN');
                if (csrfToken) {
                    CSRF_HASH = csrfToken;
                    $.ajaxSetup({ headers: { 'X-CSRF-TOKEN': csrfToken } });
                }
            } catch(e) {}
            const body = { test_id: EXAM_CONFIG.testId };
            if (CSRF_NAME) body[CSRF_NAME] = CSRF_HASH;"""

for root, dirs, files in os.walk(directory):
    for file in files:
        if file.endswith('.html'):
            filepath = os.path.join(root, file)
            with open(filepath, 'r', encoding='utf-8') as f:
                content = f.read()
            
            if old_text in content:
                content = content.replace(old_text, new_text)
                with open(filepath, 'w', encoding='utf-8') as f:
                    f.write(content)
                print(f"Updated {filepath}")
