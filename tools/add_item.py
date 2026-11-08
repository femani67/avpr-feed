#!/usr/bin/env python3
"""Add one post to the AVPR feed.

Usage:  python3 tools/add_item.py item.json [item2.json ...]

item.json fields:
  title, excerpt, rutitr, categories (list of WordPress category IDs),
  slider (bool), focus_keyword (optional),
  blocks: list of strings. A string starting with "<h2", "<ul" or "<ol" is used
          as-is; anything else becomes a paragraph.
  image_query: Wikimedia Commons search text (English works best)
  image_pick:  optional substring the chosen file title must contain
  image_alt:   Persian alt text
  image_file:  optional exact Commons file title ("File:....jpg") instead of a search

The script finds a free, landscape, >=1600px photo on Wikimedia Commons, downloads
the 1920px version into images/, adds the photo credit to the post, and puts the
item at the top of feed.json. The WordPress plugin on avpr.ir publishes it.
"""
import hashlib, json, os, re, sys, time, urllib.parse, urllib.request
from datetime import datetime, timezone

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
FEED = os.path.join(ROOT, 'feed.json')
UA = 'AVPR-Feed-Bot/1.0 (https://avpr.ir; femani67@gmail.com)'
API = 'https://commons.wikimedia.org/w/api.php'
MAX_ITEMS = 400
BAD = re.compile(r'air ?base|air force|navy|naval|military|army|usaf|raf |marine|warship|missile|fighter|bomber|cvn|uss |sailor', re.I)


def get(url, binary=False, tries=4):
    for i in range(tries):
        try:
            req = urllib.request.Request(url, headers={'User-Agent': UA})
            with urllib.request.urlopen(req, timeout=60) as r:
                data = r.read()
                return data if binary else json.loads(data)
        except urllib.error.HTTPError as e:
            if e.code in (429, 503) and i < tries - 1:
                time.sleep(5 * (i + 1)); continue
            raise
        except Exception:
            if i < tries - 1:
                time.sleep(3); continue
            raise


def used_images():
    if not os.path.exists(FEED):
        return set()
    with open(FEED, encoding='utf-8') as f:
        return {i.get('image', {}).get('source') for i in json.load(f).get('items', [])}


def find_image(query=None, pick=None, exact=None):
    params = {'action': 'query', 'prop': 'imageinfo', 'format': 'json',
              'iiprop': 'url|size|extmetadata|mime', 'iiurlwidth': '1920'}
    if exact:
        params['titles'] = exact
    else:
        params.update({'generator': 'search', 'gsrsearch': query + ' filetype:bitmap',
                       'gsrnamespace': '6', 'gsrlimit': '25'})
    data = get(API + '?' + urllib.parse.urlencode(params))
    pages = sorted((data.get('query') or {}).get('pages', {}).values(), key=lambda p: p.get('index', 0))
    seen = used_images()
    for p in pages:
        if 'imageinfo' not in p:
            continue
        i = p['imageinfo'][0]; m = i.get('extmetadata', {})
        lic = (m.get('LicenseShortName') or {}).get('value', '')
        if not exact:
            if i['width'] < 1600 or i['width'] <= i['height'] * 1.2: continue
            if 'jpeg' not in i['mime'] and 'png' not in i['mime']: continue
            if not lic or re.search('fair|non-free', lic, re.I): continue
            if pick and pick.lower() not in p['title'].lower(): continue
            if i['descriptionurl'] in seen: continue
            if BAD.search(p['title']): continue
        artist = re.sub('<[^>]+>', '', (m.get('Artist') or {}).get('value', '')).strip()[:60] or 'ناشناس'
        return {'title': p['title'], 'thumb': i.get('thumburl') or i['url'], 'page': i['descriptionurl'],
                'license': lic or 'free', 'artist': artist}
    return None


def block(t):
    t = t.strip()
    if t.startswith('<h2') or t.startswith('<h3'):
        return '<!-- wp:heading -->\n' + t + '\n<!-- /wp:heading -->'
    if t.startswith('<ul') or t.startswith('<ol'):
        tag = 'ol' if t.startswith('<ol') else 'ul'
        attr = ' {"ordered":true}' if tag == 'ol' else ''
        return '<!-- wp:list' + attr + ' -->\n' + t + '\n<!-- /wp:list -->'
    return '<!-- wp:paragraph -->\n<p>' + t + '</p>\n<!-- /wp:paragraph -->'


def add(path):
    with open(path, encoding='utf-8') as f:
        spec = json.load(f)
    for k in ('title', 'excerpt', 'blocks', 'categories'):
        if not spec.get(k):
            sys.exit(f'{path}: missing {k}')
    img = None
    if spec.get('image_file'):
        img = find_image(exact=spec['image_file'])
    if not img and spec.get('image_query'):
        img = find_image(spec['image_query'], spec.get('image_pick'))
        if not img and spec.get('image_pick'):
            img = find_image(spec['image_query'])
    for q in spec.get('image_fallbacks', []):
        if img: break
        img = find_image(q)
    if not img:
        sys.exit(f'{path}: no suitable photo found — try another image_query')

    now = datetime.now(timezone.utc)
    item_id = now.strftime('%Y%m%d%H%M%S') + '-' + hashlib.sha1(spec['title'].encode()).hexdigest()[:8]
    rel = f'images/{now:%Y}/{now:%m}/{item_id}.jpg'
    os.makedirs(os.path.join(ROOT, os.path.dirname(rel)), exist_ok=True)
    with open(os.path.join(ROOT, rel), 'wb') as f:
        f.write(get(img['thumb'], binary=True))

    credit = f"عکس: {img['artist']} / Wikimedia Commons ({img['license']})"
    content = '\n\n'.join(block(b) for b in spec['blocks'])
    content += ('\n\n<!-- wp:paragraph -->\n<p class="avpr-credit"><small><a href="' + img['page'] +
                '" target="_blank" rel="noopener">' + credit + '</a></small></p>\n<!-- /wp:paragraph -->')

    item = {'id': item_id, 'created': now.isoformat(), 'title': spec['title'], 'excerpt': spec['excerpt'],
            'rutitr': spec.get('rutitr', ''), 'slider': bool(spec.get('slider')),
            'categories': spec['categories'], 'focus_keyword': spec.get('focus_keyword', ''),
            'content': content,
            'image': {'path': rel, 'alt': spec.get('image_alt', spec['title']), 'caption': credit,
                      'source': img['page']}}
    feed = {'items': []}
    if os.path.exists(FEED):
        with open(FEED, encoding='utf-8') as f:
            feed = json.load(f)
    if any(i['title'].strip() == spec['title'].strip() for i in feed['items']):
        print(f'SKIP (duplicate title): {spec["title"]}'); return
    feed['items'].insert(0, item)
    feed['items'] = feed['items'][:MAX_ITEMS]
    feed['updated'] = now.isoformat()
    with open(FEED, 'w', encoding='utf-8') as f:
        json.dump(feed, f, ensure_ascii=False, indent=1)
    print(f'OK {item_id} | {img["title"]} | {spec["title"]}')


if __name__ == '__main__':
    for p in sys.argv[1:]:
        add(p)
