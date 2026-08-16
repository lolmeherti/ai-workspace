import json, re, sys

path = 'src/logs/app.log'
rec_fail = {}
samples = {}
parse_event_counts = {}
total_parse = 0

def truthy(v):
    return v is not None and str(v).strip() != ''

with open(path, 'r', encoding='utf-8') as f:
    for line in f:
        if 'JobParser' not in line and 'job_parse' not in line:
            continue
        m = re.search(r'Context: (\{.*\})\s*$', line)
        ctx = {}
        if m:
            try:
                ctx = json.loads(m.group(1))
            except Exception:
                ctx = {}
        if 'record failed validation' in line:
            rec = ctx.get('record') or {}
            url = ctx.get('url', '')
            title = rec.get('title')
            company = rec.get('company')
            posted = rec.get('posted_at')
            city = rec.get('city')
            country = rec.get('country')
            wm = rec.get('work_mode')
            # mirror describeRecordFailure order
            if not truthy(title):
                reason = 'missing title'
            elif not truthy(company):
                reason = 'missing company'
            elif not truthy(posted):
                reason = 'missing/invalid posted_at'
            elif '://' not in url:
                reason = 'invalid URL'
            elif wm is not None and str(wm).strip().lower() != 'remote' and (not truthy(city) or not truthy(country)):
                sub = 'city null' if not truthy(city) else 'country null'
                reason = 'missing city/country (non-remote) [' + sub + ']'
            else:
                reason = 'OTHER (title/company/posted all present)'
            rec_fail[reason] = rec_fail.get(reason, 0) + 1
            samples.setdefault(reason, []).append((url, city, country, wm, posted))
        elif 'page is a listing' in line:
            parse_event_counts['listing page (is_listing=true)'] = parse_event_counts.get('listing page (is_listing=true)', 0) + 1
        elif 'could not fetch page text' in line:
            parse_event_counts['fetch failed'] = parse_event_counts.get('fetch failed', 0) + 1
        elif 'no decodable JSON' in line:
            parse_event_counts['invalid JSON from LLM'] = parse_event_counts.get('invalid JSON from LLM', 0) + 1

n = sum(rec_fail.values())
print('record failed validation total: %d' % n)
print()
print('=== breakdown of record-failed-validation ===')
for r in sorted(rec_fail, key=lambda x: -rec_fail[x]):
    print('%4d  %s' % (rec_fail[r], r))
print()
print('=== other job_parse events ===')
for k, v in sorted(parse_event_counts.items(), key=lambda x: -x[1]):
    print('%4d  %s' % (v, k))
print()
print('=== samples: missing city/country (non-remote) ===')
for s in samples.get('missing city/country (non-remote) [country null]', [])[:20]:
    print(s)
print()
print('=== samples: missing city/country [city null] ===')
for s in samples.get('missing city/country (non-remote) [city null]', [])[:20]:
    print(s)
print()
print('=== distinct URLs among missing-city/country ===')
cc = samples.get('missing city/country (non-remote) [country null]', []) + samples.get('missing city/country (non-remote) [city null]', [])
urls = {}
for u, *_ in cc:
    urls[u] = urls.get(u, 0) + 1
print('distinct urls:', len(urls))
for u in sorted(urls, key=lambda x: -urls[x])[:20]:
    print('%3d  %s' % (urls[u], u))
