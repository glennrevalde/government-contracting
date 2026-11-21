"""
Barangay Contracts: the company's SOP on a transaction (small value procurement and bidding), the Forms &
Proformas, and the configurable templates (format, pre-printed paper, versions).

The suite records its own procuring entity and its own transactions; nothing of the demo data is changed.
The steps are worked by the people whose job they are (accounts.py):
  bidofficer  works the steps (papers, canvass, billing documents, forwarding, transmittal)
  manager     approver: skips and reopens steps, marks papers not needed, reviews, closes, keeps the SOP set-up
  collector   follows up the payment and records the cheque, records the collection
  billing     the billing pack and the invoice; accountant reviews it, manager approves it
  pmgr        delivered and accepted from the signed IAR
"""
import io
import re
import struct
import time
import zipfile

from selenium.webdriver.common.by import By

from core import fixtures as fx
from core.runner import CheckFailed, suite

S = suite('sop', 'Barangay SOP, Forms and Templates', order=85)

STEP2_PAPERS = ['Purchase request (PR)', 'Purchase order (PO)', 'Inspection and acceptance report (IAR)', 'Requisition and issue slip (RIS)',
                'Canvass forms (request for quotation)', 'Abstract of canvass', 'Disbursement voucher (DV)']
COMPANY_PAPERS = ['PhilGEPS registration', 'DTI / SEC registration', "Mayor's permit", 'Certificate of registration (COR)', 'Tax clearance',
                  'Audited financial statements', 'Income tax return']


# ===================================================================================== helpers
# (written here because core has no helpers for the Process tab of a transaction)

_N = [0]


def _file(kind, name, text='Signed copy (Selenium SOP test)'):
    """A fixture file with a name of this suite (other suites write theirs at the same time). Its content is new
    on every call: the Document Vault refuses a file it already holds."""
    _N[0] += 1
    name = 'sop_' + name
    text = f'{text} {fx.stamp()}-{_N[0]}'
    if kind == 'pdf':
        return fx.pdf(name, text)
    if kind == 'png':
        return fx.png(name)
    path = fx.jpg(name)
    with open(path, 'rb') as f:
        data = f.read()
    com = text.encode('ascii', 'replace')[:200]
    with open(path, 'wb') as f:            # a comment segment right after the start of the JPEG
        f.write(data[:2] + b'\xff\xfe' + struct.pack('>H', len(com) + 2) + com + data[2:])
    return path


def _instant(a, el):
    """Bring the element to the middle of the window at once (the pages scroll smoothly, and a click during the
    scroll can land beside the button)."""
    a.js('arguments[0].scrollIntoView({block: "center", behavior: "instant"});', el)


def click(a, target):
    el = a._target(target) if isinstance(target, str) else target
    _instant(a, el)
    a.click(el)
    return el


def click_wait(a, target):
    el = a._target(target) if isinstance(target, str) else target
    _instant(a, el)
    a.click_and_wait(el)


def submit_btn(a, text, root=None):
    click_wait(a, a._target(text, root))


def open_process(a, pid, tab='process'):
    a.go('brgy.projects', a='view', id=pid, tab=tab)
    a.assert_healthy(f'project, {tab} tab')
    return a


def card(a, code):
    """The card of a step on the Process tab, opened."""
    el = a.find(f'#step-{code}')
    a.js('arguments[0].open = true;', el)
    return el


_ROW_JS = """const c = document.getElementById('step-' + arguments[0]); if (!c) return null; c.open = true;
for (const r of c.querySelectorAll('.paper-row')) { const n = r.querySelector('.paper-name'); if (n && n.textContent.trim() === arguments[1]) return r; }
return null;"""


def paper_row(a, code, name):
    """The row of a paper in the card of a step (matched on its name)."""
    r = a.js(_ROW_JS, code, name)
    if r is None:
        names = a.js("return Array.from(document.querySelectorAll('#step-' + arguments[0] + ' .paper-row .paper-name')).map(n => n.textContent.trim())", code)
        raise CheckFailed(f'No paper {name!r} in step {code}: {names}')
    return r


def paper_why(a, code, name):
    """The line under a paper that says where it stands."""
    return a.js("return arguments[0].querySelector('.paper-meta').textContent.trim()", paper_row(a, code, name))


def row_button(a, row, text, tags='button|a'):
    return a.by_text(text, tags=tags, root=row, timeout=3, visible=False)


def has_button(a, root, text, tags='button|a'):
    try:
        a.by_text(text, tags=tags, root=root, timeout=0.3, visible=False)
        return True
    except CheckFailed:
        return False


def modal_by(a, trigger, mid):
    """Click what opens the dialog #mid and wait until it is shown (after its fade)."""
    click(a, trigger)
    m = a.wait_for(lambda: (lambda e: e if a._shown(e) and 'show' in (e.get_attribute('class') or '') else None)(a.find('#' + mid)),
                   f'the dialog {mid} did not open')
    time.sleep(0.35)
    return m


def show_modal(a, mid):
    """Open a dialog by its id, without the button (for a role whose page has no button for it)."""
    m = a.modal(mid)
    time.sleep(0.35)
    return m


def submit_modal(a, m):
    btn = [b for b in a.finds('button[type=submit], button:not([type])', m) if a._shown(b)]
    if not btn:
        raise CheckFailed('No button to send the dialog')
    click_wait(a, btn[-1])


def attach(a, code, name, path, note=''):
    """Attach (or replace) the signed copy of a paper of a step."""
    row = paper_row(a, code, name)
    btn = row.find_element(By.CSS_SELECTOR, '[data-bs-target="#mPaperAttach"]')
    m = modal_by(a, btn, 'mPaperAttach')
    a.fill(a.find('input[name=file]', m), path)
    if note:
        a.fill(a.find('input[name=note]', m), note)
    submit_modal(a, m)


def prepare(a, code, name):
    row = paper_row(a, code, name)
    click_wait(a, row_button(a, row, 'Prepare', 'button'))


def step_done(a, code, checks=None, remarks=''):
    """Tick the checks of the step (all of them by default) and press its done button."""
    c = card(a, code)
    forms = a.finds('form.step-done', c)
    if not forms:
        raise CheckFailed(f'Step {code} has no done button (is it the current step?)')
    f = forms[0]
    a.js("""for (const b of arguments[0].querySelectorAll('input[name="checks[]"]')) {
              const want = arguments[1] === null || arguments[1].includes(b.value); if (b.checked !== want) b.click(); }""", f, checks)
    if remarks:
        a.fill(a.find('input[name=remarks]', f), remarks)
    click_wait(a, a.find('button.btn-success', f))


def now_text(a):
    return a.find('.sop-head-main').text


def step_state(a, code):
    return a.find(f'#step-{code} .step-state').get_attribute('textContent').strip()


def gate(a, code):
    return [li.get_attribute('textContent').strip() for li in a.finds(f'#step-{code} .step-gate li')]


def save_step_data(a, code, values):
    """The small form of a step (PhilGEPS, forwarding, transmittal, release ...)."""
    c = card(a, code)
    f = None
    for x in a.finds('form', c):
        if a.finds('input[name=do][value=sop_save]', x):
            f = x
            break
    if f is None:
        raise CheckFailed(f'Step {code} has no form to save its data')
    for k, v in values.items():
        a.fill(a.find(f'[name="{k}"]', f), v)
    click_wait(a, a.find('button.btn-outline-primary', f))


def saved(a, words=None):
    """The page says it saved (with these words) and has no error of the server or of the browser."""
    msg = a.expect_saved(words)
    a.assert_healthy('the page after saving')
    return msg


def refused(a, words=None):
    """The page says it refused (with these words) and has no error of the server or of the browser."""
    msg = a.expect_refused(words)
    a.assert_healthy('the page after the refusal')
    return msg


def project_status(a):
    return a.find('.proj-head-status').text


def new_transaction(a, values, process):
    a.go('brgy.projects', a='new')
    a.assert_healthy('new transaction form')
    a.fill(a.find(f'input[name=process][value={process}]'), True)
    a.fill_form(values)
    submit_btn(a, 'Record the transaction')
    msg = saved(a, 'recorded')
    pid = int(a.current_params()['id'])
    return pid, msg


# ===================================================================================== the entity

@S.case('A procuring entity of our own for the transactions of the suite')
def entity(t):
    a = t.app
    a.as_user('bidofficer')
    a.go('brgy.entities', a='new')
    a.assert_healthy('new entity form')
    name = fx.unique('Barangay Selenium SOP')
    a.fill_form({'official_name': name, 'entity_type': '=barangay', 'address': 'Barangay Hall, Rizal St.', 'barangay': 'Selenium',
                 'city_municipality': 'Lapu-Lapu City', 'province': 'Cebu', 'region': 'Region VII', 'tin': '000-555-101-000', 'phone': '(032) 555-0101'})
    submit_btn(a, 'Add entity')
    saved(a, 'Procuring entity added')
    t.equal(a.h1(), name, 'the entity page')
    t.state['entity_id'] = int(a.current_params()['id'])
    t.state['entity_name'] = name


@S.case('Seals of our entity: upload, shown on the page, a file that is not a picture refused, remove', needs=['entity_id'])
def seals(t):
    a = t.app
    a.as_user('bidofficer')
    eid = t.state['entity_id']
    a.go('brgy.entities', a='view', id=eid)
    a.assert_healthy('entity page')
    boxes = a.finds('.seal-box form')
    t.equal(len(boxes), 2, 'two seal boxes (barangay, city / municipality)')

    def upload(i, path):
        f = a.finds('.seal-box form')[i]
        a.fill(a.find('input[name=seal]', f), path)
        click_wait(a, a.find('button[name=do][value=seal_upload]', f))

    upload(0, _file('png', 'seal_brgy.png'))
    saved(a, 'Seal saved')
    upload(1, _file('jpg', 'seal_city.jpg'))
    saved(a, 'Seal saved')
    imgs = a.finds('.seal-box .seal-pic img')
    t.equal(len(imgs), 2, 'both seals shown')
    for img, kind in zip(imgs, ('image/png', 'image/jpeg')):
        status, headers, body = a.fetch(img.get_attribute('src'))
        t.equal(status, 200, 'the seal picture opens')
        t.contains(headers.get('content-type', ''), kind, 'type of the seal picture')
        t.check(a.js('return arguments[0].complete && arguments[0].naturalWidth > 0', img), 'the seal picture is drawn')
    # not a picture
    upload(0, fx.text_file('sop_not_a_seal.png', 'this is text, not a picture'))
    refused(a, 'Choose the picture of the seal')
    # remove the barangay seal; the city's stays
    f = a.finds('.seal-box form')[0]
    click_wait(a, a.find('button[name=do][value=seal_remove]', f))
    saved(a, 'Seal removed')
    boxes = a.finds('.seal-box')
    t.check(not a.finds('.seal-pic img', boxes[0]), 'the barangay seal is gone')
    t.check(a.finds('.seal-pic img', boxes[1]), 'the city seal stays')


# ===================================================================================== small value procurement, step by step

@S.case('Record a new small value transaction with the transaction form fields', needs=['entity_id'])
def svp_new(t):
    a = t.app
    a.as_user('bidofficer')
    c = fx.code('SV')
    title = fx.unique('Selenium SOP supplies for the barangay hall')
    today = fx.today()
    a.go('brgy.projects', a='new')
    a.assert_healthy('new transaction form')
    t.check(a.find('input[name=process][value=svp]').is_selected(), 'small value procurement is chosen by default')
    t.check(a.exists('[data-process-only] input[name=requestor]', visible=True), 'the transaction form card shows for small value')
    # v33: no positions of the requestor and the inspector; the tradename is chosen from the Tradenames list
    t.check(not a.exists('[name=requestor_position]') and not a.exists('[name=inspector_position]'), 'no position of the requestor or of the inspector')
    t.check(a.exists('select[name=tradename]', visible=True), 'the tradename is a drop-down of the Tradenames list')
    # v34: no card of the numbers and dates of the barangay's papers; the estimates and targets in the transaction form
    t.check(not a.exists('[name=ris_no]') and not a.exists('[name=iar_no]') and not a.exists('[name=dv_date]'), "no card of the numbers and dates of the barangay's papers")
    t.check(a.exists('#estBlock input[name=est_gross]', visible=True), 'the estimates and targets are in the transaction form')
    values = {
        'txn_date': today, 'txn_type': '=purchases', 'requestor': 'Liza M. Tumulak',
        'inspector': 'Engr. Ramon P. Ybanez', 'purpose': 'For Barangay Office Use',
        'sales_rep_id': 'Maria Santos', 'fund': 'General Fund',
        'est_gross': '20000', 'est_wt_rate': '=1', 'est_profit_rate': '=13', 'est_receipt_rate': '=3', 'est_sop': '500',
        'reference_no': 'PR-' + c,
        'title': title, 'entity_id': '=' + str(t.state['entity_id']), 'category': '=goods', 'mode_id': 'Small Value Procurement',
        'abc_amount': '20000', 'end_user': 'Barangay Office', 'funding_source': 'General Fund 2026', 'delivery_site': 'Barangay Hall',
        'delivery_days': '7', 'checker_id': 'Ana Cruz', 'approver_id': 'Victor Aquino', 'submitter_id': 'Rosa Dizon',
        'pm_id': 'Leo Navarro', 'collector_id': 'Paolo Ramos',
    }
    a.fill(a.find('input[name=process][value=svp]'), True)
    a.fill_form(values)
    submit_btn(a, 'Record the transaction')
    msg = saved(a, 'recorded')
    t.contains(msg, 'Small value procurement (SOP)', 'the message names the process')
    m = re.search(r'Transaction (\S+) recorded', msg)
    t.check(m, 'the transaction form number is given: ' + msg)
    pid = int(a.current_params()['id'])
    t.state.update({'svp': pid, 'svp_no': a.h1(), 'svp_title': title, 'svp_tf': m.group(1), 'svp_code': c})
    a.assert_healthy('the new transaction')
    t.equal(a.current_params().get('tab', 'process'), 'process', 'the Process tab opens first')
    t.equal(a.find('.nav-link.active').text.strip(), 'Process (SOP)', 'the open tab')
    t.equal(a.find('.sop-head-title').text.strip(), 'Small value procurement (SOP)', 'the process')
    head = a.find('.sop-head-main').text
    t.contains(head, m.group(1), 'the transaction form number')
    t.contains(head, 'Purchases', 'the type')
    t.contains(head, 'Maria Santos', 'the sales representative')
    t.contains(head, '0 of 9 steps done', 'progress')
    t.contains(head, 'Now: step 1, Receive transaction details', 'the step now')
    t.equal(len(a.finds('.step-card')), 9, 'nine steps')
    dl = card(a, 'svp1').find_element(By.CSS_SELECTOR, 'dl').text
    for v in ('Liza M. Tumulak', 'Engr. Ramon P. Ybanez', '20,000.00', 'For Barangay Office Use'):
        t.contains(dl, v, 'details of step 1')
    # v34: gross 20,000.00, withholding tax 1% = 200.00, cheque amount 19,800.00, profit 13% = 2,600.00
    for v in ('Estimates and targets', '19,800.00', '2,600.00', '600.00'):
        t.contains(dl, v, 'the estimates of step 1')


@S.case('The types Cash Out (purchases) and Kaliwaan (purchases) are on the transaction form, and a Cash Out transaction ticks its box', needs=['entity_id'])
def txn_types(t):
    a = t.app
    a.as_user('bidofficer')
    a.go('brgy.projects', a='new')
    a.assert_healthy('new transaction form')
    opts = a.js("return Array.from(document.querySelector('select[name=txn_type]').options).map(o => o.value + '|' + o.text)")
    for want in ('cash_out|Cash Out (purchases)', 'kaliwaan|Kaliwaan (purchases)', 'purchases|Purchases', 'construction|Construction'):
        t.check(want in opts, f'the type {want.split("|")[1]} can be chosen ({opts})')
    title = fx.unique('Selenium SOP cash out')
    a.fill(a.find('input[name=process][value=svp]'), True)
    a.fill_form({'title': title, 'entity_id': '=' + str(t.state['entity_id']), 'category': '=goods', 'mode_id': 'Small Value Procurement', 'abc_amount': '9000',
                 'txn_date': fx.today(), 'txn_type': '=cash_out', 'requestor': 'Liza M. Tumulak', 'inspector': 'Engr. Ramon P. Ybanez', 'purpose': 'Cash out (test)',
                 'checker_id': 'Ana Cruz', 'approver_id': 'Victor Aquino'})
    submit_btn(a, 'Record the transaction')
    saved(a, 'recorded')
    pid = int(a.current_params()['id'])
    t.state['cash_out'] = pid
    t.contains(now_text(a), 'Cash Out (purchases)', 'the type of the transaction')
    prepare(a, 'svp1', 'Transaction form')
    saved(a, 'Prepared:')
    click_wait(a, paper_row(a, 'svp1', 'Transaction form').find_element(By.CSS_SELECTOR, 'a[href*="p=brgy.forms"][href*="a=view"]'))
    a.assert_healthy('the transaction form')
    boxes = a.js("return Array.from(document.querySelectorAll('.formdoc .fx-types .fx-opt')).map(b => (b.querySelector('.fx-chk.is-on') ? 'X ' : '') + b.textContent.trim())")
    t.check('X CASH OUT' in boxes, f'CASH OUT is ticked on the transaction form ({boxes})')
    t.check('KALIWAAN' in boxes and 'PURCHASES' in boxes and 'CONSTRUCTION' in boxes, f'the four boxes of the pad ({boxes})')


@S.case('Step 1 is refused without the items, and the steps are done in order', needs=['svp'])
def svp_order(t):
    a = t.app
    a.as_user('bidofficer')
    pid = t.state['svp']
    open_process(a, pid)
    g = ' '.join(gate(a, 'svp1'))
    t.contains(g, 'Enter the items', 'still to do on step 1')
    t.contains(g, 'Transaction form: not prepared yet', 'still to do on step 1')
    step_done(a, 'svp1')
    t.contains(refused(a, 'not finished'), 'Enter the items (Price schedule tab)', 'the reason')
    # step 2 sent while step 1 is not done (the step of the form changed in the page)
    f = card(a, 'svp1').find_element(By.CSS_SELECTOR, 'form.step-done')
    a.set_value(f.find_element(By.CSS_SELECTOR, 'input[name=step]'), 'svp2')
    click_wait(a, a.find('button.btn-success', f))
    t.contains(refused(a, 'in order'), 'Receive transaction details', 'the step that comes first')
    t.contains(now_text(a), '0 of 9 steps done', 'nothing was marked done')


@S.case('The price schedule of the transaction (its items) is entered and saved', needs=['svp'])
def svp_lines(t):
    a = t.app
    a.as_user('bidofficer')
    pid = t.state['svp']
    open_process(a, pid, 'lines')
    rows = [('Bond paper, A4, 70gsm', 'ream', '10', '250', '260'), ('Ballpen, black', 'box', '5', '100', '110')]
    for i, (d, u, q, p, e) in enumerate(rows):
        a.fill(f'lines[{i}][description]', d)
        a.fill(f'lines[{i}][unit]', u)
        a.fill(f'lines[{i}][qty]', q)
        if a.exists(f'[name="lines[{i}][unit_cost]"]'):
            a.fill(f'lines[{i}][unit_cost]', str(int(p) * 0.8))
        a.fill(f'lines[{i}][unit_price]', p)
        a.fill(f'lines[{i}][est_unit_price]', e)
    t.contains(a.find('#lgTotal').text, '3,000.00', 'the total while typing')
    submit_btn(a, 'Save price schedule')
    saved(a, 'Price schedule saved')
    t.contains(a.find('#lgTotal').text, '3,000.00', 'the total after saving')
    t.equal(a.value('lines[1][description]'), 'Ballpen, black', 'the second line')
    open_process(a, pid)
    t.contains(card(a, 'svp1').find_element(By.CSS_SELECTOR, 'dl').text, '2 line(s), 3,000.00', 'step 1 sees the items')


@S.case('Details not complete: the request for details goes to the sales representative as a task', needs=['svp'])
def svp_details_request(t):
    a = t.app
    a.as_user('bidofficer')
    pid = t.state['svp']
    open_process(a, pid)
    what = 'Validate the price of the ballpens with the supplier (test ' + fx.stamp() + ')'
    m = modal_by(a, a.by_text('Details not complete', tags='button', root=card(a, 'svp1')), 'mDetails')
    a.fill(a.find('textarea[name=remarks]', m), what)
    submit_modal(a, m)
    a.assert_healthy('Process tab after the request')
    saved(a, 'Details requested')
    open_process(a, pid, 'tasks')
    t.contains(a.main_text(), 'Complete the transaction details', 'the task')
    t.contains(a.main_text(), 'Maria Santos', 'given to the sales representative')


@S.case('Step 1: the transaction form prepared, the checks ticked, the step done', needs=['svp'])
def svp_step1(t):
    a = t.app
    a.as_user('bidofficer')
    pid = t.state['svp']
    open_process(a, pid)
    prepare(a, 'svp1', 'Transaction form')
    msg = saved(a, 'Prepared:')
    t.state['svp_tf_form'] = re.search(r'Prepared: (\S+?)\.', msg).group(1)
    row = paper_row(a, 'svp1', 'Transaction form')
    t.check(has_button(a, row, 'Print', 'a'), 'the prepared paper has its Print link')
    t.contains(row.text, t.state['svp_tf_form'], 'the form number on the paper')
    step_done(a, 'svp1', checks=['Project title'])
    t.contains(refused(a, 'not finished'), 'Tick "Approved budget"', 'the checks left')
    step_done(a, 'svp1', remarks='Details complete from the sales representative')
    saved(a, 'Step 1 done: Receive transaction details')
    st = step_state(a, 'svp1')
    t.contains(st, 'Done', 'step 1')
    t.contains(st, 'Rosa Dizon', 'done by')
    t.contains(now_text(a), 'Now: step 2, Prepare the required documents', 'the step now')


@S.case('Step 2: every paper prepared from its proforma, one printed, signed copies attached (PDF and JPG), one replaced', needs=['svp'])
def svp_step2(t):
    a = t.app
    a.as_user('bidofficer')
    pid = t.state['svp']
    open_process(a, pid)
    step_done(a, 'svp2')
    t.contains(refused(a, 'not finished'), 'not prepared yet', 'papers not prepared')
    click_wait(a, a.by_text('Prepare every paper of this step', tags='button', root=card(a, 'svp2')))
    saved(a, '7 paper(s) prepared')
    for name in STEP2_PAPERS:
        row = paper_row(a, 'svp2', name)
        t.check(has_button(a, row, 'Print', 'a'), f'{name}: Print')
    # print the purchase request: clean, with the items of the price schedule
    href = row_button(a, paper_row(a, 'svp2', 'Purchase request (PR)'), 'Print', 'a').get_attribute('href')
    a.open(href)
    a.assert_healthy('print of the purchase request')
    doc = a.find('.formdoc')
    t.contains(doc.text, 'Bond paper, A4, 70gsm', 'the item on the purchase request')
    t.contains(doc.text, '2,600.00', 'the estimated cost of the line')
    t.check('is-draft' not in doc.get_attribute('class'), 'printed clean (no DRAFT mark)')
    open_process(a, pid)
    # a paper of our own on the step
    extra = fx.unique('Certificate of availability of funds')
    m = modal_by(a, a.by_text('Add another paper to this step', tags='button', root=card(a, 'svp2')), 'mPaperAdd')
    a.fill(a.find('input[name=name]', m), extra)
    a.fill(a.find('input[name=copies]', m), '2')
    a.fill(a.find('input[name=needs_signature]', m), True)
    submit_modal(a, m)
    saved(a, 'Paper added to the step')
    t.contains(paper_why(a, 'svp2', extra), 'Attach it', 'the paper of our own')
    t.contains(paper_row(a, 'svp2', extra).text, '2 copies', 'its copies')
    t.state['svp_extra'] = extra
    t.contains(' '.join(gate(a, 'svp2')), extra + ': not prepared yet', 'the paper of our own is asked for')
    # a file that is not a PDF or a picture
    attach(a, 'svp2', 'Disbursement voucher (DV)', fx.text_file('sop_dv.txt', 'not a scan'))
    refused(a, 'PDF, JPG or JPEG')
    # the signed copies
    signed = [('svp1', 'Transaction form', 'pdf'), ('svp2', 'Purchase request (PR)', 'pdf'), ('svp2', 'Purchase order (PO)', 'jpg'),
              ('svp2', 'Inspection and acceptance report (IAR)', 'pdf'), ('svp2', 'Abstract of canvass', 'pdf'),
              ('svp2', 'Disbursement voucher (DV)', 'jpg'), ('svp2', extra, 'jpg')]
    for code, name, kind in signed:
        def one(code=code, name=name, kind=kind):
            attach(a, code, name, _file(kind, f'{code}_{len(name)}.{kind}', name + ' (signed)'), note='Signed (test)')
            saved(a, 'Signed copy attached')
            t.contains(paper_why(a, code, name), 'Signed copy attached', name)
            t.check(has_button(a, paper_row(a, code, name), 'Signed copy', 'a'), 'the link to the signed copy')
        t.sub(f'{name}: signed copy ({kind.upper()}) attached', one)
    # the signed copy opens
    link = row_button(a, paper_row(a, 'svp2', 'Purchase order (PO)'), 'Signed copy', 'a').get_attribute('href')
    status, headers, body = a.fetch(link)
    t.equal(status, 200, 'the signed copy opens')
    t.contains(headers.get('content-type', ''), 'image/jpeg', 'the JPG')
    # replace the purchase request: the older version is kept
    old = row_button(a, paper_row(a, 'svp2', 'Purchase request (PR)'), 'Signed copy', 'a').get_attribute('href')
    t.check(has_button(a, paper_row(a, 'svp2', 'Purchase request (PR)'), 'Replace', 'button'), 'Replace is offered')
    attach(a, 'svp2', 'Purchase request (PR)', _file('pdf', 'pr_v2.pdf', 'Purchase request (signed again)'))
    saved(a, 'Signed copy attached')
    new = row_button(a, paper_row(a, 'svp2', 'Purchase request (PR)'), 'Signed copy', 'a').get_attribute('href')
    t.check(new != old, 'the paper points to the new copy')
    doc_id = int(re.search(r'[?&]id=(\d+)', new).group(1))
    a.go('brgy.vault', a='view', id=doc_id)
    a.assert_healthy('vault document')
    vers = a.xfind("//div[contains(@class,'card-header')][contains(., 'Versions')]/following-sibling::*[1]").text
    t.contains(vers, 'v1', 'the older version is kept')
    t.contains(vers, 'v2', 'the new version')
    t.contains(vers, 'Superseded', 'the older version is superseded')


@S.case('Not needed: the bid officer cannot waive a paper; the approver marks one not needed with the reason', needs=['svp'])
def svp_waive(t):
    a = t.app
    a.as_user('bidofficer')
    pid = t.state['svp']
    open_process(a, pid)
    ris = 'Requisition and issue slip (RIS)'
    t.check(not has_button(a, paper_row(a, 'svp2', ris), 'Not needed', 'button'), 'no Not needed for the bid officer')
    t.check(not has_button(a, card(a, 'svp2'), 'Skip', 'button'), 'no Skip for the bid officer')
    a.as_user('manager')
    open_process(a, pid)
    m = modal_by(a, row_button(a, paper_row(a, 'svp2', ris), 'Not needed', 'button'), 'mPaperWaive')
    t.contains(m.text, ris, 'the dialog names the paper')
    reason = 'The barangay issues its own RIS from the supply office (test)'
    a.fill(a.find('textarea[name=remarks]', m), reason)
    submit_modal(a, m)
    saved(a, 'not needed')
    t.contains(paper_why(a, 'svp2', ris), 'Not needed: ' + reason, 'the reason on the paper')
    t.check(has_button(a, paper_row(a, 'svp2', ris), 'Needed again', 'button'), 'it can be needed again')


@S.case('Step 2 done once every paper is prepared, signed or not needed', needs=['svp'])
def svp_step2_done(t):
    a = t.app
    a.as_user('bidofficer')
    open_process(a, t.state['svp'])
    step_done(a, 'svp2')
    saved(a, 'Step 2 done: Prepare the required documents')
    t.contains(now_text(a), 'Now: step 3, Supplier canvassing', 'the step now')


@S.case('Step 3: three quotations with the signed canvass forms make the award', needs=['svp'])
def svp_step3(t):
    a = t.app
    a.as_user('bidofficer')
    pid = t.state['svp']
    open_process(a, pid)
    c3 = card(a, 'svp3')
    m = modal_by(a, a.by_text("Add the company's quotation", tags='button', root=c3), 'mCanvass')
    t.equal(a.find('input[name=is_company]', m).get_attribute('value'), '1', 'the company quotation')
    t.check(a.find('input[name=total_amount]', m).get_attribute('readonly') is not None, 'its total comes from the price schedule')
    a.fill(a.find('input[name=validity]', m), '30 calendar days')
    a.fill(a.find('input[name=file]', m), _file('pdf', 'canvass_company.pdf', 'Canvass form, the company (signed)'))
    submit_modal(a, m)
    saved(a, 'Quotation saved')
    step_done(a, 'svp3')
    t.contains(refused(a, 'not finished'), 'three (3) suppliers (1 so far)', 'three suppliers needed')
    stamp = fx.stamp()
    for name, amount, remark, kind in ((f'Northgate Office Depot {stamp}', '3200', 'passed', 'pdf'), (f'Bluewave Trading {stamp}', '2950', 'failed', 'jpg')):
        m = modal_by(a, a.by_text('Add a quotation', tags='button', root=card(a, 'svp3')), 'mCanvass')
        a.fill_form({'supplier_name': name, 'total_amount': amount, 'remarks': '=' + remark, 'validity': '30 calendar days',
                     'philgeps_no': '1234' + stamp[-4:], 'contact_no': '0917 555 0101', 'address': 'Cebu City'}, root=m)
        a.fill(a.find('input[name=file]', m), _file(kind, f'canvass_{remark}.{kind}', 'Canvass form signed by ' + name))
        submit_modal(a, m)
        saved(a, 'Quotation saved')
    rows = [r.text for r in a.finds('#step-svp3 .canvass-table tbody tr')]
    t.equal(len(rows), 3, 'three quotations')
    best = [r for r in rows if 'lowest that passed' in r]
    t.check(best and '(the company)' in best[0], 'the company quoted the lowest that passed: ' + ' | '.join(rows))
    t.contains(' '.join(rows), 'Failed', 'the failed quotation')
    t.contains(paper_why(a, 'svp2', 'Canvass forms (request for quotation)'), '3 canvass forms signed', 'the canvass paper counts the signed forms')
    step_done(a, 'svp3')
    msg = saved(a, 'Step 3 done: Supplier canvassing')
    t.contains(msg, 'the purchase order is recorded as the award (3,000.00)', 'the award')
    t.contains(project_status(a), 'Awarded', 'the transaction is awarded')
    t.contains(now_text(a), 'Now: step 4, Prepare billing documents', 'the step now')


@S.case('The Contracting Dashboard lists our transaction on the SOP board, waiting for payment', needs=['svp'])
def svp_board(t):
    a = t.app
    a.as_user('bidofficer')
    a.go('brgy.dashboard')
    a.assert_healthy('dashboard')
    head = a.xfind("//div[contains(@class,'card-header')][contains(., 'Transactions on the SOP')]")
    t.check(head, 'the SOP board')
    mine = [c for c in a.finds('.sb-card') if t.state['svp_title'] in c.text]
    t.check(mine, 'our transaction is on the board')
    txt = mine[0].text
    t.contains(txt, t.state['svp_tf'], 'its transaction form number')
    t.contains(txt, 'waiting for payment', 'waiting for payment')
    col = mine[0].find_element(By.XPATH, "./ancestor::div[contains(@class,'sb-col')]").find_element(By.CSS_SELECTOR, '.sb-title').text
    t.contains(col, 'Prepare billing documents', 'the column of step 4')
    click_wait(a, mine[0])
    t.equal(a.current_params().get('id'), str(t.state['svp']), 'the card opens the transaction')


@S.case('Step 4 on hold (special case F): papers held, step refused, billing pack refused', needs=['svp'])
def svp_hold(t):
    a = t.app
    a.as_user('bidofficer')
    pid = t.state['svp']
    open_process(a, pid)
    for name in ('Sales invoice (SI)', 'Official receipt (OR) / collection receipt', 'BIR Form 2307'):
        t.contains(paper_why(a, 'svp4', name), 'Held until the payment is released', name)
        row = paper_row(a, 'svp4', name)
        t.check(not row.find_elements(By.CSS_SELECTOR, '[data-bs-target="#mPaperAttach"]'), f'{name}: nothing to attach while held')
    t.contains(card(a, 'svp4').find_element(By.CSS_SELECTOR, '.pay-box').text, 'Payment not released yet', 'the payment box')
    step_done(a, 'svp4')
    t.contains(refused(a, 'not finished'), 'special case F', 'held')
    a.as_user('billing')
    a.go('brgy.billing', a='new', project_id=pid)
    a.assert_healthy('new billing pack')
    submit_btn(a, 'Prepare billing')
    refused(a, 'special case F')


@S.case('Payment: a follow-up logged, then the cheque is ready (collector)', needs=['svp'])
def svp_cheque(t):
    a = t.app
    a.as_user('collector')
    pid = t.state['svp']
    open_process(a, pid)
    note = 'Treasurer: the DV is with the City Accountant (test ' + fx.stamp() + ')'
    m = modal_by(a, a.by_text('Log a follow-up', tags='button', root=card(a, 'svp4')), 'mFollow')
    a.fill(a.find('textarea[name=remarks]', m), note)
    submit_modal(a, m)
    saved(a, 'Follow-up logged')
    t.contains(card(a, 'svp4').find_element(By.CSS_SELECTOR, '.pay-box').text, 'Payment follow-up: ' + note[:40], 'the follow-up on the step')
    cheque = 'CHK' + fx.stamp()
    m = modal_by(a, a.by_text('Cheque ready', tags='button', root=card(a, 'svp4')), 'mPayRelease')
    amount = a.find('input[name=cheque_amount]', m).get_attribute('value')
    t.check(float(amount) > 2800 and float(amount) < 3000, 'the expected cheque, net of the taxes withheld: ' + amount)
    # v34: the tradename the cheque is payable to, from the Tradenames list
    tn_sel = a.find('select[name=cheque_tradename]', m)
    tn_opts = [o.text.strip() for o in tn_sel.find_elements(By.TAG_NAME, 'option')]
    t.check(len(tn_opts) >= 1, 'the cheque: a Tradename drop-down of the tradenames: ' + ', '.join(tn_opts))
    tn_chosen = tn_sel.get_attribute('value')
    a.fill(a.find('input[name=cheque_no]', m), cheque)
    submit_modal(a, m)
    saved(a, 'Payment released')
    box = card(a, 'svp4').find_element(By.CSS_SELECTOR, '.pay-box').text
    t.contains(box, 'Payment released', 'the payment box')
    t.contains(box, 'Cheque ' + cheque, 'the cheque')
    t.contains(box, 'payable to ' + tn_chosen, 'the tradename of the cheque')
    t.state['svp_cheque'] = cheque
    t.contains(paper_why(a, 'svp4', 'Sales invoice (SI)'), 'Issue the sales invoice from a billing pack', 'the SI is no longer held')


@S.case('Step 4: delivered and accepted from the signed IAR, billed, the payment received, SI, OR and 2307 attached', needs=['svp_cheque'])
def svp_step4(t):
    a = t.app
    pid = t.state['svp']
    code = t.state['svp_code']
    # delivered and accepted in full (project manager)
    a.as_user('pmgr')
    open_process(a, pid)
    m = modal_by(a, a.by_text('Delivered and accepted', tags='button', root=card(a, 'svp4')), 'mDeliverAll')
    submit_modal(a, m)
    saved(a, 'Delivery recorded and accepted in full')
    t.contains(card(a, 'svp4').find_element(By.CSS_SELECTOR, '.link-list').text, '3,000.00 of 3,000.00 accepted', 'accepted in full')
    # the billing pack (billing officer)
    a.as_user('billing')
    open_process(a, pid)
    click_wait(a, a.by_text('Make the billing pack', tags='a', root=card(a, 'svp4')))
    a.assert_healthy('billing pack form')
    click(a, a.find('[data-fill-all="#billLines"]'))
    a.fill('si_no', 'SI-' + code)
    t.contains(a.find('#billTotal').text, '3,000.00', 'everything accepted is billed')
    submit_btn(a, 'Prepare billing')
    saved(a, 'Billing pack prepared')
    bid = int(a.current_params()['id'])
    t.state['svp_billing'] = bid

    def wf(user, label, words):
        a.as_user(user)
        a.go('brgy.billing', a='view', id=bid)
        a.assert_healthy('billing pack')
        m = modal_by(a, a.by_text(label, tags='button'), a.by_text(label, tags='button').get_attribute('data-bs-target').lstrip('#'))
        submit_modal(a, m)
        saved(a, words)
    wf('billing', 'Send for review', 'Sent for review')
    wf('accountant', 'Mark reviewed', 'Marked reviewed')
    wf('manager', 'Approve', 'Approved')
    a.as_user('billing')
    a.go('brgy.billing', a='view', id=bid)
    click_wait(a, a.by_text('Issue the invoice', tags='button'))
    saved(a, 'posted to the Customer:Job')
    # the payment received with its official receipt (collector)
    a.as_user('collector')
    a.go('brgy.collections', a='new', billing_id=bid)
    a.assert_healthy('collection form')
    t.equal(a.value('check_no'), t.state['svp_cheque'], 'the cheque of the Process tab fills the payment')
    click(a, a.find('#fillExpected'))
    a.fill('receipt_no', 'OR-' + code)
    t.contains(a.find('#colLeft').text, 'Settles the billing in full', 'paid in full')
    submit_btn(a, 'Post the collection')
    saved(a, 'posted')
    # the signed SI, OR and 2307 (bid officer)
    a.as_user('bidofficer')
    open_process(a, pid)
    t.contains(paper_why(a, 'svp4', 'Sales invoice (SI)'), 'SI-' + code, 'the SI issued')
    t.contains(paper_why(a, 'svp4', 'Official receipt (OR) / collection receipt'), 'OR-' + code, 'the receipt')
    step_done(a, 'svp4')
    t.contains(refused(a, 'not finished'), 'BIR Form 2307: attach the copies signed by the proprietor', 'the 2307 is asked for')
    for name, kind in (('Sales invoice (SI)', 'pdf'), ('Official receipt (OR) / collection receipt', 'jpg'), ('BIR Form 2307', 'pdf')):
        attach(a, 'svp4', name, _file(kind, f'svp4_{len(name)}.{kind}', name + ' (signed)'))
        saved(a, 'Signed copy attached')
    step_done(a, 'svp4')
    saved(a, 'Step 4 done: Prepare billing documents')


@S.case('Step 5: the review is refused to whoever prepared the billing documents and done by another person', needs=['svp_billing'])
def svp_step5(t):
    a = t.app
    a.as_user('bidofficer')
    pid = t.state['svp']
    open_process(a, pid)
    step_done(a, 'svp5')
    t.contains(refused(a, 'not finished'), 'someone other than the person who prepared the billing documents', 'review by another person')
    a.as_user('manager')
    open_process(a, pid)
    step_done(a, 'svp5', remarks='Computations checked against the DV and the 2307')
    saved(a, 'Step 5 done: Review documents')
    t.contains(step_state(a, 'svp5'), 'Victor Aquino', 'reviewed by the approver')


@S.case('Skip and Open this step again: done by the approver, refused to the bid officer', needs=['svp_billing'])
def svp_skip(t):
    a = t.app
    pid = t.state['svp']
    a.as_user('manager')
    open_process(a, pid)
    m = modal_by(a, a.by_text('Skip', tags='button', root=card(a, 'svp6'), exact=True), 'mSkip')
    t.equal(a.find('input[name=step]', m).get_attribute('value'), 'svp6', 'the dialog is for step 6')
    a.fill(a.find('textarea[name=remarks]', m), 'Encoded at month end (test)')
    submit_modal(a, m)
    saved(a, 'Step skipped')
    t.equal(step_state(a, 'svp6'), 'Skipped', 'step 6 skipped')
    t.contains(card(a, 'svp6').text, 'Encoded at month end (test)', 'the reason on the step')
    t.contains(now_text(a), 'Now: step 7, Forward documents', 'the transaction moved on')
    m = modal_by(a, a.by_text('Open this step again', tags='button', root=card(a, 'svp6')), 'mReopenStep')
    a.fill(a.find('textarea[name=remarks]', m), 'Monitoring is encoded per transaction after all (test)')
    submit_modal(a, m)
    saved(a, 'The step is open again')
    t.equal(step_state(a, 'svp6'), 'Now', 'step 6 is to do again')
    t.contains(now_text(a), 'Now: step 6, File and monitor records', 'back to step 6')
    # the bid officer has neither button, and the server refuses both when sent anyway
    a.as_user('bidofficer')
    open_process(a, pid)
    t.check(not has_button(a, card(a, 'svp6'), 'Skip', 'button'), 'no Skip button for the bid officer')
    t.check(not has_button(a, card(a, 'svp5'), 'Open this step again', 'button'), 'no Open this step again for the bid officer')
    m = show_modal(a, 'mSkip')
    a.set_value(a.find('input[name=step]', m), 'svp6')
    a.fill(a.find('textarea[name=remarks]', m), 'Trying to skip (test)')
    submit_modal(a, m)
    refused(a, 'Only an approver skips a step')
    t.equal(step_state(a, 'svp6'), 'Now', 'step 6 is still to do')
    m = show_modal(a, 'mReopenStep')
    a.set_value(a.find('input[name=step]', m), 'svp5')
    a.fill(a.find('textarea[name=remarks]', m), 'Trying to open it again (test)')
    submit_modal(a, m)
    refused(a, 'Only an approver opens a step again')
    t.contains(step_state(a, 'svp5'), 'Done', 'step 5 stays done')


@S.case('Steps 6 to 9: monitoring, forwarding, transmittal and release; the last step closes the contract', needs=['svp_billing'])
def svp_close(t):
    a = t.app
    pid = t.state['svp']
    a.as_user('bidofficer')
    open_process(a, pid)
    step_done(a, 'svp6')
    saved(a, 'Step 6 done')
    # 7. forwarded to accounting
    step_done(a, 'svp7')
    t.contains(refused(a, 'not finished'), 'forwarded to the Accounting Department', 'the date of forwarding')
    save_step_data(a, 'svp7', {'forwarded_to': 'Accounting Department', 'forwarded_date': fx.today()})
    saved(a, 'Saved')
    step_done(a, 'svp7')
    saved(a, 'Step 7 done: Forward documents')
    # 8. the transmittal
    prepare(a, 'svp8', 'Transmittal')
    tr_no = re.search(r'Prepared: (\S+?)\.', saved(a, 'Prepared:')).group(1)
    f = card(a, 'svp8')
    t.equal(a.find('input[name=transmittal_no]', f).get_attribute('value'), tr_no, 'the transmittal number comes from the form')
    save_step_data(a, 'svp8', {'transmittal_no': tr_no})
    saved(a, 'Saved')
    step_done(a, 'svp8')
    saved(a, 'Step 8 done: Prepare transmittal')
    # 9. released, picked up at the office
    c9 = card(a, 'svp9')
    click(a, a.find('label[for=rmPick]', c9))
    save_step_data(a, 'svp9', {'released_to': 'Barangay Treasurer (test)', 'released_date': fx.today()})
    saved(a, 'Saved')
    t.check(a.find('#rmPick').is_selected(), 'picked up at the office is kept')
    a.as_user('manager')
    open_process(a, pid)
    step_done(a, 'svp9')
    t.contains(refused(a, 'not finished'), 'Attach the signed transmittal', 'the signed transmittal must come back')
    a.as_user('bidofficer')
    open_process(a, pid)
    attach(a, 'svp9', 'Transmittal', _file('pdf', 'transmittal.pdf', 'Transmittal received by the Barangay Treasurer'))
    saved(a, 'Signed copy attached')
    a.as_user('manager')
    open_process(a, pid)
    step_done(a, 'svp9', remarks='Signed transmittal returned')
    msg = saved(a, 'Step 9 done: Release of documents')
    t.contains(msg, 'Every step of the SOP is done and the contract is closed out', 'closed out')
    t.contains(project_status(a), 'Closed', 'the transaction is closed')
    t.contains(now_text(a), 'Every step is done', 'every step done')
    t.state['svp_closed'] = True


@S.case('The closed transaction is on the Transaction Monitoring report', needs=['svp_closed'])
def svp_monitoring(t):
    a = t.app
    a.as_user('manager')
    a.go('rpt.brgy_monitoring', process='svp', stage='done')
    a.assert_healthy('Transaction Monitoring')
    rows = [r for r in a.table_rows() if t.state['svp_no'] in r]
    t.check(rows, 'our transaction is listed among the closed ones')
    r = rows[0]
    code = t.state['svp_code']
    for v in (t.state['svp_tf'], 'Closed', 'Small value', 'SI-' + code, 'OR-' + code, t.state['svp_cheque'], 'Signed copies', '3,000.00', 'Released'):
        t.contains(r, v, 'the row of the report')


@S.case('Download the signed copies of the transaction as one ZIP', needs=['svp_closed'])
def svp_zip(t):
    a = t.app
    a.as_user('bidofficer')
    open_process(a, t.state['svp'])
    href = a.by_text('Download signed copies', tags='a').get_attribute('href')
    status, headers, body = a.fetch(href)
    t.equal(status, 200, 'the ZIP downloads')
    t.contains(headers.get('content-type', ''), 'application/zip', 'type')
    t.check(body[:2] == b'PK', 'a ZIP file')
    names = zipfile.ZipFile(io.BytesIO(body)).namelist()
    # 11 papers with their signed copy (transaction form, PR, PO, IAR, abstract, DV, the paper of our own, SI, OR, 2307,
    # transmittal; the RIS is not needed) and the three canvass forms
    t.equal(len(names), 14, f'every signed copy is in it: {names}')
    joined = ' | '.join(names)
    for part in ('Step 1 Transaction form.pdf', 'Step 2 Purchase request (PR).pdf', 'Step 2 Purchase order (PO).jpg', 'Step 4 BIR Form 2307.pdf',
                 'Step 8 Transmittal.pdf', 'Canvass form'):
        t.contains(joined, part, 'a file of the ZIP')


@S.case('The Document Vault lists our signed copies on its Signed copies tab', needs=['svp_closed'])
def svp_vault(t):
    a = t.app
    a.as_user('bidofficer')
    a.go('brgy.vault', tab='signed', q=t.state['svp_no'])
    a.assert_healthy('Signed copies tab')
    t.contains(a.find('.nav-link.active').text, 'Signed copies', 'the tab')
    rows = a.table_rows('#docTable tbody tr')
    text = ' | '.join(rows)
    t.check(len(rows) >= 14, f'every signed copy of the transaction ({len(rows)})')
    for v in ('Purchase request (PR) (signed) - ' + t.state['svp_no'], 'Transmittal (signed) - ' + t.state['svp_no'], 'Canvass form signed by'):
        t.contains(text, v, 'a signed copy')


# ===================================================================================== bidding

@S.case('A bidding transaction of our own goes through the first steps up to the eligibility papers (step 7)', needs=['entity_id'])
def bidding(t):
    a = t.app
    a.as_user('bidofficer')
    c = fx.code('BD')
    title = fx.unique('Selenium SOP tanod radios')
    deadline = fx.today(10) + 'T10:00'
    a.go('brgy.projects', a='new')
    a.assert_healthy('new transaction form')
    a.fill(a.find('input[name=process][value=bidding]'), True)
    t.check(a.exists('input[name=bid_deadline]', visible=True), 'the key dates show for a bidding')
    a.fill_form({'title': title, 'entity_id': '=' + str(t.state['entity_id']), 'category': '=goods', 'mode_id': 'Competitive Bidding',
                 'abc_amount': '250000', 'reference_no': 'ITB-' + c, 'posting_date': fx.today(), 'bid_deadline': deadline,
                 'bid_opening_at': fx.today(10) + 'T10:30', 'specs': 'Handheld two-way radios, 5 W, with chargers',
                 'txn_type': '=purchases', 'requestor': 'Kgd. Teresa O. Ramos', 'inspector': 'Engr. Danilo F. Cabrera',
                 'checker_id': 'Ana Cruz', 'approver_id': 'Victor Aquino', 'submitter_id': 'Rosa Dizon'})
    submit_btn(a, 'Record the transaction')
    t.contains(saved(a, 'recorded'), 'Bidding (SOP)', 'the process')
    pid = int(a.current_params()['id'])
    t.state['bid'] = pid
    t.contains(now_text(a), 'of 21 steps done', 'the bidding and payment steps')
    # 1. details
    prepare(a, 'bid1', 'Bidding form')
    saved(a, 'Prepared:')
    step_done(a, 'bid1')
    saved(a, 'Step 1 done: Receive bidding details')
    # 2. verified
    step_done(a, 'bid2')
    saved(a, 'Step 2 done: Verify completeness')
    # 3. PhilGEPS
    step_done(a, 'bid3')
    t.contains(refused(a, 'not finished'), 'PhilGEPS reference number', 'the posting is asked for')
    save_step_data(a, 'bid3', {'philgeps_ref': '1265' + fx.stamp()[-4:], 'posting_date': fx.today()})
    saved(a, 'Saved')
    step_done(a, 'bid3')
    saved(a, 'Step 3 done: Posting on PhilGEPS')
    # 4. pre-bid papers
    click_wait(a, a.by_text('Prepare every paper of this step', tags='button', root=card(a, 'bid4')))
    saved(a, '3 paper(s) prepared')
    step_done(a, 'bid4')
    saved(a, 'Step 4 done: Prepare pre-bid documents')
    # 5. the representative and the SPA
    save_step_data(a, 'bid5', {'submitter_id': 'Rosa Dizon'})
    saved(a, 'Saved')
    prepare(a, 'bid5', 'Special power of attorney (SPA)')
    saved(a, 'Prepared:')
    t.contains(paper_row(a, 'bid5', 'Special power of attorney (SPA)').text, 'notarized', 'the SPA is notarized')
    step_done(a, 'bid5')
    saved(a, 'Step 5 done: Assign authorized representative')
    # 6. legal papers
    click_wait(a, a.by_text('Prepare every paper of this step', tags='button', root=card(a, 'bid6')))
    saved(a, '2 paper(s) prepared')
    step_done(a, 'bid6')
    saved(a, 'Step 6 done: Prepare legal documents')
    t.contains(now_text(a), 'Now: step 7, Print eligibility documents', 'the eligibility step')
    # 7. the company papers in the vault answer the step
    onfile, missing = [], []
    for name in COMPANY_PAPERS:
        why = paper_why(a, 'bid7', name)
        if why.startswith('On file:'):
            onfile.append(name)
            t.check(has_button(a, paper_row(a, 'bid7', name), 'On file', 'a'), f'{name}: the copy on file opens')
        else:
            t.contains(why, 'No valid copy in the vault', name)
            missing.append(name)
    t.check(len(onfile) >= 4, f'the company papers on file answer the step (on file: {onfile}; missing: {missing})')
    g = ' '.join(gate(a, 'bid7'))
    for name in onfile:
        t.check(name + ':' not in g, f'{name} is not asked for')
    t.state['bid_missing'] = missing
    if missing:
        for name in missing:
            t.contains(g, name + ': no valid copy on file', 'still to do')
        step_done(a, 'bid7')
        t.contains(refused(a, 'not finished'), missing[0] + ': no valid copy on file', 'refused while a paper is not valid')
        a.as_user('manager')
        open_process(a, pid)
        for name in missing:
            m = modal_by(a, row_button(a, paper_row(a, 'bid7', name), 'Not needed', 'button'), 'mPaperWaive')
            a.fill(a.find('textarea[name=remarks]', m), 'The BAC accepts the renewal receipt (test)')
            submit_modal(a, m)
            saved(a, 'not needed')
        a.as_user('bidofficer')
        open_process(a, pid)
    step_done(a, 'bid7')
    saved(a, 'Step 7 done: Print eligibility documents')
    t.contains(now_text(a), '7 of 21 steps done', 'progress')
    t.contains(now_text(a), 'Now: step 8, Prepare additional requirements', 'the step now')


STEP8 = {'Statement of ongoing contracts': 'Statement of all Ongoing Government', 'Statement of similar contracts': 'single largest completed contract',
         'Supplier profile': 'Legal Aspect', 'Letter of intent': 'Manifestation of Intent to Participate', 'Technical specifications': 'SECTION VII-TECHNICAL SPECIFICATIONS',
         'Production details': 'SECTION VI-SCHEDULE OF REQUIREMENTS', 'Manpower schedule': 'MANPOWER REQUIREMENTS', 'Warranty certificate': 'After-Sales Service and Warranty Provision'}


@S.case('Step 8: the additional requirements are prepared from the company\'s own forms (statements, supplier profile, letter of intent, specifications, schedule, manpower, warranty)', needs=['bid'])
def bid_step8(t):
    a = t.app
    a.as_user('bidofficer')
    open_process(a, t.state['bid'])
    click_wait(a, a.by_text('Prepare every paper of this step', tags='button', root=card(a, 'bid8')))
    saved(a, 'paper(s) prepared')
    for name, words in STEP8.items():
        def one(name=name, words=words):
            open_process(a, t.state['bid'])
            click_wait(a, paper_row(a, 'bid8', name).find_element(By.CSS_SELECTOR, 'a[href*="p=brgy.forms"][href*="a=view"]'))
            a.assert_healthy(name)
            doc = a.find('.formdoc').text
            t.contains(doc, words, 'its heading')
            t.check('{{' not in doc, 'every field filled or marked')
            if name in ('Letter of intent', 'Manpower schedule', 'Warranty certificate'):
                t.check(a.exists('.formdoc .fx-letterhead'), 'the company letterhead')
            if name in ('Letter of intent', 'Technical specifications', 'Production details', 'Manpower schedule', 'Warranty certificate'):
                t.check(a.exists('.formdoc .fx-sigs'), 'the signatories of the Format tab')
            t.contains(a.find('.col-xl-3').text, 'Folio, landscape' if name == 'Statement of similar contracts' else ('Legal, landscape' if name.startswith('Statement') else 'Legal, portrait'),
                       'the paper of the company\'s form')
        t.sub(name, one)
    open_process(a, t.state['bid'])
    step_done(a, 'bid8')
    saved(a, 'Step 8 done')


@S.case('The Process tab and the Format tab of a template fit a phone screen', needs=['svp'])
def phone(t):
    a = t.app
    a.as_user('bidofficer')
    try:
        a.phone()
        for key in ('svp', 'bid'):
            if key not in t.state:
                continue

            def one(key=key):
                open_process(a, t.state[key])
                ov = a.overflow_x()
                t.check(ov <= 1, f'the page is {ov}px wider than the phone: ' + ', '.join(a.widest_elements()))
            t.sub(f'{"Small value" if key == "svp" else "Bidding"} transaction', one)

        def fmt_tab():
            a.as_user('manager')
            a.go('brgy.templates')
            click_wait(a, a.xfind("//a[contains(@class,'tpl-card')][.//span[@class='tpl-name'][starts-with(normalize-space(.), 'BAC resolution')]]"))
            click_wait(a, a.by_text('Format', tags='a', exact=True))
            a.assert_healthy('the Format tab on a phone')
            ov = a.overflow_x()
            t.check(ov <= 1, f'the page is {ov}px wider than the phone: ' + ', '.join(a.widest_elements()))
            t.check(a.exists('[data-sig-card]'), 'the signatories can be changed on a phone')
        t.sub('Format tab of a template (letterhead and signatories)', fmt_tab)
    finally:
        a.desktop()


# ===================================================================================== SOP Steps & Papers

@S.case('SOP Steps & Papers: the approver adds a paper and a step of their own, edits them, and removes the step', needs=['entity_id'])
def sop_setup(t):
    a = t.app
    a.as_user('bidofficer')
    a.go('brgy.process')
    a.assert_healthy('SOP Steps & Papers')
    t.check(not a.exists('[data-bs-target="#mStep"]', visible=True), 'the bid officer cannot add or change steps')
    a.as_user('manager')
    a.go('brgy.dashboard')
    t.check('brgy.process' in a.sidebar_pages(), 'SOP Steps & Papers is on the menu of the approver')
    click_wait(a, a.find('#sidebar a.menu-link[href*="p=brgy.process"]'))
    a.assert_healthy('SOP Steps & Papers from the menu')
    t.equal(a.h1(), 'SOP Steps & Papers', 'the page opened from the menu')
    click_wait(a, a.by_text('Papers', tags='a', exact=True))
    a.assert_healthy('Papers tab')
    code = 'sel_' + fx.code('P').lower()
    name = fx.unique('Selenium certificate of posting')
    m = modal_by(a, a.by_text('New paper', tags='button'), 'mPaper')
    a.fill_form({'code': code, 'name': name, 'source': '=received', 'doc_type': '=other', 'copies': '2', 'description': 'Kept by the suite (test)'}, root=m)
    a.fill(a.find('input[name=needs_signature]', m), True)
    submit_modal(a, m)
    saved(a, 'Paper saved')
    row = a.xfind(f"//tr[.//code[normalize-space(.)='{code}']]")
    t.contains(row.text, name, 'the new paper')
    t.contains(row.text, 'Received', 'how it is made')
    # edit it
    name2 = name + ' (edited)'
    m = modal_by(a, row.find_element(By.CSS_SELECTOR, '[data-bs-target="#mPaper"]'), 'mPaper')
    t.check(a.find('input[name=code]', m).get_attribute('readonly') is not None, 'the code of a paper is fixed')
    a.fill(a.find('input[name=name]', m), name2)
    submit_modal(a, m)
    saved(a, 'Paper saved')
    t.contains(a.xfind(f"//tr[.//code[normalize-space(.)='{code}']]").text, name2, 'the paper renamed')
    # a step of our own (inactive, so no open transaction gets it), with the paper on it
    a.go('brgy.process', tab='svp')
    title = fx.unique('Selenium check of the papers')
    m = modal_by(a, a.by_text('New step', tags='button'), 'mStep')
    t.check(a.exists(f'input[name="papers[]"][value="{code}"]'), 'the new paper can be put on a step')
    a.fill_form({'step_no': '99', 'title': title, 'section': 'Z', 'section_title': 'Selenium', 'sort_order': '9900', 'is_active': False,
                 'instructions': 'A step of the test suite.', 'action': '=checks', 'role_hint': 'Tester', 'checks': 'First check\nSecond check'}, root=m)
    a.fill(a.find(f'input[name="papers[]"][value="{code}"]', m), True)
    ticks = a.finds('input[name="txn_types[]"]', m)
    t.check(len(ticks) >= 4 and all(x.is_selected() for x in ticks), 'a new step is for every type of transaction')
    a.fill(a.find('input[name="txn_types[]"][value="kaliwaan"]', m), False)
    submit_modal(a, m)
    saved(a, 'Step added')

    def mine(ttl):
        return a.xfind(f"//div[contains(@class,'sf-step')][.//div[contains(@class,'sf-title')][contains(., '{ttl}')]]")
    st = mine(title)
    t.check('is-off' in st.get_attribute('class'), 'the step is inactive')
    t.contains(st.text, '(inactive)', 'marked inactive')
    t.contains(st.text, name2, 'its paper')
    t.contains(st.text, 'Second check', 'its checks')
    t.contains(st.text, 'Checks ticked', 'what the program does')
    t.contains(st.text, 'Only for Cash Out (purchases), Purchases, Construction', 'not for Kaliwaan')
    # edit the step
    title2 = title + ' (edited)'
    m = modal_by(a, st.find_element(By.CSS_SELECTOR, '[data-bs-target="#mStep"]'), 'mStep')
    t.equal(a.find('input[name=title]', m).get_attribute('value'), title, 'the dialog holds the step')
    t.check(a.find(f'input[name="papers[]"][value="{code}"]', m).is_selected(), 'its paper is ticked')
    a.fill(a.find('input[name=title]', m), title2)
    submit_modal(a, m)
    saved(a, 'Step saved')
    t.contains(mine(title2).text, title2, 'the step renamed')
    # an inactive step does not reach an open transaction
    if 'bid' in t.state:
        open_process(a, t.state['bid'])
        t.check(title not in a.main_text(), 'the inactive step is not on an open transaction')
        a.go('brgy.process', tab='svp')
    # remove it
    click_wait(a, mine(title2).find_element(By.CSS_SELECTOR, 'form[data-confirm] button'))
    saved(a, 'Step deleted')
    t.check(title not in a.main_text(), 'the step is gone')


# ===================================================================================== Forms & Proformas

@S.case('Forms & Proformas: formats, a form printed with the layout and on pre-printed paper, Word, values, signed copy', needs=['svp'])
def forms(t):
    a = t.app
    a.as_user('bidofficer')
    a.go('brgy.forms')
    a.assert_healthy('Forms & Proformas')
    click_wait(a, a.by_text('Formats', tags='a'))
    a.assert_healthy('Formats of the forms')
    rows = [r for r in a.table_rows() if r.startswith('Transaction form')]
    t.check(rows, 'the transaction form is on the formats list')
    t.check('With the layout' in rows[0] or 'On pre-printed paper' in rows[0], 'how it prints: ' + rows[0])
    t.contains(rows[0], 'None', 'the forms of the SOP need no approval')
    # a form of our own from the transaction form template (it has positions on pre-printed paper)
    a.go('brgy.forms', a='new')
    a.assert_healthy('Prepare a form')
    title = fx.unique('Selenium transaction form copy')
    a.fill('template_id', 'Transaction form')
    a.fill('project_id', t.state['svp_no'])
    a.fill('title', title)
    submit_btn(a, 'Prepare')
    a.assert_healthy('form editor')
    fid = int(a.current_params()['id'])
    t.equal(a.current_params().get('a'), 'edit', 'the new form opens in the editor')
    panel = a.xfind("//div[contains(@class,'card')][.//div[contains(@class,'card-header')][contains(., 'Values on the pre-printed paper')]]")
    inputs = a.finds('input[name^="values["]', panel)
    t.check(len(inputs) >= 5, f'the values panel lists the placed fields ({len(inputs)})')
    purpose = a.find('input[name="values[txn.purpose]"]', panel)
    t.equal(purpose.get_attribute('value'), 'For Barangay Office Use', 'the value comes from the transaction')
    mark = 'Selenium purpose ' + fx.stamp()
    a.fill(purpose, mark)
    submit_btn(a, 'Save')
    saved(a, 'Form saved')
    t.equal(a.value('values[txn.purpose]'), mark, 'the value is kept')
    # printed with the layout and on the pre-printed paper
    a.go('brgy.forms', a='print', id=fid, mode='layout')
    a.assert_healthy('print with the layout')
    t.check(a.exists('.formdoc'), 'the layout')
    t.check(a.exists('.doc-tools a', visible=True) and a.by_text('On pre-printed paper', tags='a'), 'the switch to pre-printed paper')
    click_wait(a, a.by_text('On pre-printed paper', tags='a'))
    a.assert_healthy('print on pre-printed paper')
    t.check(a.exists('.pos-page') and not a.exists('.pos-grid'), 'only the values, no grid')
    t.contains(a.find('.pos-page').text, mark, 'the value typed on the form prints')
    t.contains(a.find('.pos-page').text, t.state['svp_tf'], 'the series number prints')
    # Word
    status, headers, body = a.fetch(a.url('brgy.forms', a='word', id=fid))
    t.equal(status, 200, 'the Word file downloads')
    t.contains(headers.get('content-type', ''), 'msword', 'type')
    t.contains(headers.get('content-disposition', ''), '.doc', 'a .doc file')
    t.contains(body.decode('utf-8', 'replace'), t.state['svp_tf'], 'the form is in the file')
    # the signed copy
    a.go('brgy.forms', a='view', id=fid)
    a.assert_healthy('form page')
    sc = a.xfind("//div[contains(@class,'card')][.//div[contains(@class,'card-header')][contains(., 'Signed copy')]]")
    t.contains(sc.text, 'No signed copy yet', 'nothing attached yet')
    a.fill(a.find('input[name=file]', sc), _file('jpg', 'form_signed.jpg'))
    a.fill(a.find('input[name=note]', sc), 'Signed by the treasurer (test)')
    click_wait(a, a.by_text('Attach', tags='button', root=sc))
    saved(a, 'Signed copy attached and filed in the Document Vault')
    sc = a.xfind("//div[contains(@class,'card')][.//div[contains(@class,'card-header')][contains(., 'Signed copy')]]")
    t.contains(sc.text, 'sop_form_signed.jpg', 'the signed copy is listed')
    a.go('brgy.forms', q=title)
    t.contains(' '.join(a.table_rows()), 'signed copy', 'the list shows it has a signed copy')


# ===================================================================================== templates

def _tpl(a, tid, tab):
    a.go('brgy.templates', a='view', id=tid, tab=tab)
    a.assert_healthy(f'template, {tab} tab')


@S.case('Templates: a copy of the purchase request with its own format (paper A5, margins, a column hidden), reset', needs=['entity_id'])
def tpl_format(t):
    a = t.app
    a.as_user('manager')
    a.go('brgy.templates')
    a.assert_healthy('Proforma & Form Templates')
    click_wait(a, a.xfind("//a[contains(@class,'tpl-card')][.//span[@class='tpl-name'][normalize-space(.)='Purchase request (PR)']]"))
    click_wait(a, a.by_text('Duplicate', tags='button'))
    a.assert_healthy('copy of the template')
    t.contains(a.h1(), 'Purchase request (PR) (copy)', 'the copy opens in the editor')
    name = fx.unique('Selenium purchase request')
    a.fill('name', name)
    a.fill('change_note', 'Renamed by the suite')
    submit_btn(a, 'Save')
    t.contains(saved(a, 'Template saved'), 'version 2', 'a new version')
    tid = int(a.current_params()['id'])
    t.state.update({'tpl': tid, 'tpl_name': name})
    # Format: A5, margins 8 mm, the estimated unit cost not printed
    _tpl(a, tid, 'format')
    a.fill('paper', '=A5')
    for k in ('margin_top', 'margin_right', 'margin_bottom', 'margin_left'):
        a.fill(k, '8')
    box = a.find('input[name="col[pr.items][est_unit][show]"]')
    a.js('arguments[0].closest("details").open = true;', box)
    a.fill(box, False)
    a.wait_for(lambda: a.find('#fmtPaper').text.startswith('A5') and a.find('#fmtState').text == 'Live preview'
               and 'Unit Cost' not in a.find('[data-fmt-doc] table.fx-items thead').text, 'the live preview did not follow the format', timeout=10)
    doc = a.find('[data-fmt-doc]')
    t.equal(a.js('return arguments[0].style.getPropertyValue("--paper-w").trim()', doc), '148mm', 'the preview is A5 wide')
    t.equal(a.js('return [arguments[0].style.paddingTop, arguments[0].style.paddingLeft].join(" ")', doc), '8mm 8mm', 'the preview margins')
    t.contains(a.find('[data-fmt-doc] table.fx-items thead').text, 'Estimated', 'the other columns stay')
    submit_btn(a, 'Save the format')
    t.contains(saved(a, 'Saved as version'), 'version 3', 'the format is a new version')
    t.equal(a.value('paper'), 'A5', 'paper kept')
    t.equal(a.value('margin_left'), '8', 'margins kept')
    t.check(not a.find('input[name="col[pr.items][est_unit][show]"]').is_selected(), 'the column stays hidden')
    t.check('Unit Cost' not in a.find('[data-fmt-doc] table.fx-items thead').text, 'the saved preview has no estimated unit cost')
    t.contains(a.find('#fmtPaper').text, 'A5, portrait', 'the paper of the preview')
    a.go('brgy.forms', a='formats')
    rows = [r for r in a.table_rows() if r.startswith(name)]
    t.check(rows, 'our copy is on the formats list')
    t.contains(rows[0], 'A5, portrait', 'its paper on the list')
    t.contains(rows[0], 'margins 8 / 8 / 8 / 8 mm', 'its margins on the list')
    # back to the defaults
    _tpl(a, tid, 'format')
    click_wait(a, a.find('button[name=do][value=format_reset]'))
    a.assert_healthy('format after the reset')
    t.equal(a.value('margin_top'), '15', 'default margins')
    t.check(a.find('input[name="col[pr.items][est_unit][show]"]').is_selected(), 'every column printed again')
    # the purchase request has no {{letterhead}}: the Format tab offers to put it at the top of the text
    btn = a.find('button[form="fmtPutLh"]')
    a.js('arguments[0].closest("details").open = true;', btn)
    click_wait(a, btn)
    saved(a, 'is now at the top of the text')
    _tpl(a, tid, 'format')
    t.check(not a.exists('button[form="fmtPutLh"]'), 'the text has {{letterhead}} now')
    t.check(a.exists('button[form="fmtPutSig"]'), 'still no {{signatories}}: the button to put them stays')


@S.case('Templates: on our copy a field is placed on the pre-printed paper, a scan put behind and removed, a test print, the print mode switched', needs=['tpl'])
def tpl_positions(t):
    a = t.app
    a.as_user('manager')
    tid = t.state['tpl']
    _tpl(a, tid, 'positions')
    sel = a.find('#posAddField')
    key, label = a.options(sel)[0]
    a.select(sel, '=' + key)
    click(a, a.find('#posAddBtn'))
    a.wait_for(lambda: a.finds('#posProps [data-p="x"]'), 'the place of the field is not offered')
    a.fill(a.find('#posProps [data-p="x"]'), '42')
    a.fill(a.find('#posProps [data-p="y"]'), '33')
    a.wait_for(lambda: '42, 33' in a.find('#posList').text, 'the list did not follow the place typed')
    chip = a.find('#posCanvas .pos-chip.is-sel')
    t.check(chip, 'the field is on the page')
    submit_btn(a, 'Save the positions')
    t.contains(saved(a, 'Saved as version'), 'version', 'positions saved')
    t.contains(a.find('#posList').text, '42, 33', 'the place is kept')
    t.equal(len(a.finds('#posCanvas .pos-chip')), 1, 'one field on the page')
    # the scan of the paper behind the fields
    a.fill(a.find('input[name=bg]'), _file('jpg', 'preprinted_scan.jpg'))
    click_wait(a, a.by_text('Upload', tags='button'))
    a.assert_healthy('positions with the scan')
    bg = a.js('return JSON.parse(document.getElementById("posData").textContent).bg')
    t.check(bg and 'a=bg' in bg, 'the scan is behind the fields')
    t.contains(a.find('#posCanvas').value_of_css_property('background-image'), 'a=bg', 'drawn behind')
    status, headers, _ = a.fetch(bg)
    t.equal(status, 200, 'the scan opens')
    t.contains(headers.get('content-type', ''), 'image/jpeg', 'the picture')
    click_wait(a, a.by_text('Remove the scan', tags='button'))
    t.check(not a.js('return JSON.parse(document.getElementById("posData").textContent).bg'), 'the scan is removed')
    t.check(not has_button(a, a.find('main'), 'Remove the scan', 'button'), 'no scan to remove')
    # test print with the grid
    test = a.by_text('Test print (grid)', tags='a').get_attribute('href')
    a.open(test)
    a.assert_healthy('test print')
    t.check(a.exists('svg.pos-grid'), 'the millimetre grid')
    t.check(a.finds('.pos-page .pos-f'), 'the field on the test page')
    # print mode: on pre-printed paper, then with the layout again
    _tpl(a, tid, 'positions')
    click_wait(a, a.find('label[for=pmP]'))
    saved(a, 'now print only the values, on the pre-printed paper')
    t.contains(a.find('.tab-body').text, 'On pre-printed paper (values only)', 'the template says how it prints')
    _tpl(a, tid, 'positions')
    t.contains(a.by_text('Pre-printed paper', tags='a').text, 'on', 'the tab says the template prints on pre-printed paper')
    t.check(a.find('#pmP').is_selected(), 'the mode is chosen')
    click_wait(a, a.find('label[for=pmL]'))
    saved(a, 'now print with the layout')
    t.state['tpl_pos'] = True


@S.case('Templates: the versions of our copy are listed and an older one is put back', needs=['tpl_pos'])
def tpl_versions(t):
    a = t.app
    a.as_user('manager')
    tid = t.state['tpl']
    _tpl(a, tid, 'versions')
    items = [li.text for li in a.finds('.list-group-item')]
    text = ' | '.join(items)
    for v in ('Copied from purchase_request', 'Renamed by the suite', 'Format: A5', 'Format put back to the defaults', 'Positions on pre-printed paper: 1 field(s)',
              'Prints on pre-printed paper', 'Prints with the layout'):
        t.contains(text, v, 'a version')
    t.check(len(items) >= 7, f'every change is a version ({len(items)})')
    t.contains(items[0], 'current', 'the newest is the current one')
    newest = int(re.match(r'v(\d+)', items[0]).group(1))
    li = a.xfind("//li[contains(@class,'list-group-item')][contains(., 'Format: A5')]")
    vno = int(re.match(r'v(\d+)', li.text).group(1))
    click_wait(a, li.find_element(By.CSS_SELECTOR, 'button'))
    msg = saved(a, 'is the current text and format again')
    t.contains(msg, f'Version {vno}', 'the version put back')

    def restored():
        _tpl(a, tid, 'format')
        t.equal(a.value('paper'), 'A5', 'the paper of that version')
        t.equal(a.value('margin_top'), '8', 'the margins of that version')
        t.check(not a.find('input[name="col[pr.items][est_unit][show]"]').is_selected(), 'the hidden column of that version')
    t.sub(f'Version {vno} put back: its paper, margins and columns are the format again', restored)

    def kept():
        _tpl(a, tid, 'versions')
        top = a.finds('.list-group-item')[0].text
        t.check(int(re.match(r'v(\d+)', top).group(1)) == newest + 1 and 'current' in top and f'Version {vno} put back' in top,
                f'the message says "{msg}", but the newest version is {top!r} (v{newest} stays the current one)')
    t.sub('Putting a version back is kept as a new version, as the message says', kept)


@S.case('Templates: our copy, with no form made from it, is deleted', needs=['tpl'])
def tpl_delete(t):
    a = t.app
    a.as_user('manager')
    _tpl(a, t.state['tpl'], 'preview')
    click_wait(a, a.find('form[data-confirm] button.btn-outline-danger'))
    a.assert_healthy('templates after the delete')
    t.equal(a.current_page(), 'brgy.templates', 'back on the list')
    t.check(t.state['tpl_name'] not in a.main_text(), 'our copy is gone')


@S.case('Templates: the letterhead, pictures, signatories and footer of a copy of the letter of intent (Format tab)', needs=['entity_id'])
def tpl_letterhead(t):
    a = t.app
    a.as_user('manager')
    a.go('brgy.templates')
    click_wait(a, a.xfind("//a[contains(@class,'tpl-card')][.//span[@class='tpl-name'][starts-with(normalize-space(.), 'Letter of intent')]]"))
    click_wait(a, a.by_text('Duplicate', tags='button'))
    name = fx.unique('Selenium letter of intent')
    a.fill('name', name)
    submit_btn(a, 'Save')
    saved(a, 'Template saved')
    tid = int(a.current_params()['id'])
    t.state['tpl_lh'] = tid
    _tpl(a, tid, 'format')
    doc = '[data-fmt-doc]'
    t.check(a.exists(doc + ' .fx-letterhead') and a.exists(doc + ' .fx-sigs'), 'the preview has the letterhead and the signatures')
    t.equal(a.value('lh_style'), 'company', 'the company letterhead (shared)')
    t.check(a.exists('[data-lh-shared]', visible=True), 'it says the company letterhead is shared')

    def preview_has(text, msg):
        a.wait_for(lambda: text in a.find(doc).text and a.find('#fmtState').text == 'Live preview', msg, timeout=10)

    # its own letterhead: the settings of the company letterhead are the start; new lines show in the preview
    a.fill('lh_style', '=own')
    t.check(not a.exists('[data-lh-shared]', visible=True), 'its own letterhead is not shared')
    t.contains(a.value('lh_lines'), '{{company.name_upper}}', 'the lines start from the company letterhead')
    a.fill('lh_lines', '# SELENIUM LETTERHEAD\nUnit 5, Test Building\n**Bold line {{company.name}}**')
    preview_has('SELENIUM LETTERHEAD', 'the new letterhead lines are not in the preview')
    t.check(a.exists(doc + ' .fx-letterhead b'), 'the bold line')
    # the signatories: one more from the list, moved up, the first removed
    blocks = len(a.finds('[data-sig-card]'))
    a.fill(a.find('[data-sig-preset]'), 'Conforme of the company')
    click(a, a.find('[data-sig-add]'))
    t.equal(len(a.finds('[data-sig-card]')), blocks + 1, 'a signatory added')
    preview_has('Conforme:', 'the new signatory is not in the preview')
    click(a, a.finds('[data-sig-card]')[-1].find_element(By.CSS_SELECTOR, '[data-sig-move="-1"]'))
    t.contains(a.finds('[data-sig-card]')[0].text, '1', 'numbered again')
    t.equal(a.finds('[data-sig-card] textarea[name$="[lead]"]')[0].get_attribute('value'), 'Conforme:', 'moved up')
    click(a, a.finds('[data-sig-card]')[-1].find_element(By.CSS_SELECTOR, '[data-sig-remove]'))
    t.equal(len(a.finds('[data-sig-card]')), blocks, 'the other one removed')
    a.wait_for(lambda: 'Respectfully yours' not in a.find(doc).text, 'the removed signatory stays in the preview', timeout=10)
    # a footer at the bottom of the page, centred and bold
    box = a.find('input[name=footer]')
    a.js('arguments[0].closest("details").open = true;', box)
    a.fill(box, 'OFFICE OF THE SELENIUM')
    a.fill('footer_pos', '=bottom')
    a.fill('footer_align', '=center')
    a.fill('footer_bold', True)
    preview_has('OFFICE OF THE SELENIUM', 'the footer is not in the preview')
    t.check('has-foot-bottom' in a.find(doc).get_attribute('class'), 'the footer is kept at the bottom of the page')
    submit_btn(a, 'Save the format')
    saved(a, 'Saved as version')
    t.equal(a.value('lh_style'), 'own', 'its own letterhead kept')
    t.contains(a.find(doc).text, 'SELENIUM LETTERHEAD', 'the saved letterhead')
    t.equal(a.finds('[data-sig-card] textarea[name$="[lead]"]')[0].get_attribute('value'), 'Conforme:', 'the signatories kept in their order')
    # picture A uploaded at once, then shown on the right of the letterhead
    inp = a.find('input[data-lhpic-file]')
    a.js('arguments[0].hidden = false;', inp)
    inp.send_keys(_file('png', 'letterhead_pic.png'))             # a picture chosen is uploaded at once
    a.wait_for(lambda: 'saved=1' in a.driver.current_url and a.js('return document.readyState') == 'complete', 'the picture was not uploaded', timeout=30)
    a.wait_ready()
    a.assert_healthy('the Format tab after the upload')
    img = a.find('[data-lhpic-img="a"] img')
    status, headers, body = a.fetch(img.get_attribute('src'))
    t.equal(status, 200, 'the picture opens')
    t.contains(headers.get('content-type', ''), 'image/png', 'its type')
    a.fill('lh_right', '=pic_a')
    a.wait_for(lambda: a.exists(doc + ' .fx-letterhead img[src*="p=file.lhpic"]'), 'picture A is not in the preview', timeout=10)
    submit_btn(a, 'Save the format')
    saved(a, 'Saved as version')
    # the printed sample: the letterhead with its picture, the signatures and the footer
    a.go('brgy.templates', a='print', id=tid)
    a.assert_healthy('the printed sample')
    t.check(a.exists('.formdoc .fx-letterhead img[src*="p=file.lhpic"]'), 'the picture on the printed page')
    t.contains(a.find('.formdoc .fx-footer').text, 'OFFICE OF THE SELENIUM', 'the footer on the printed page')
    t.contains(a.find('.formdoc .fx-sigs').text, 'Conforme:', 'the signatures on the printed page')
    # a template without {{signatories}} in its text: the Format tab offers to put it at the end of the text
    _tpl(a, tid, 'format')
    t.check(not a.exists('button[form="fmtPutSig"]'), 'nothing to put while the text has {{signatories}}')


@S.case('Templates: our copy of the letter of intent is deleted', needs=['tpl_lh'])
def tpl_letterhead_delete(t):
    a = t.app
    a.as_user('manager')
    _tpl(a, t.state['tpl_lh'], 'preview')
    click_wait(a, a.find('form[data-confirm] button.btn-outline-danger'))
    a.assert_healthy('templates after the delete')
    t.equal(a.current_page(), 'brgy.templates', 'back on the list')


# ===================================================================================== settings

@S.case('Contracting Settings: the SOP card is shown and the page saves without changes', needs=['entity_id'])
def settings(t):
    a = t.app
    a.as_user('manager')
    a.go('brgy.settings')
    a.assert_healthy('Contracting Settings')
    sop = a.xfind("//div[contains(@class,'card')][.//div[contains(@class,'card-header')][contains(., 'The SOP')]]")
    for k in ('brgy_svp_limit', 'brgy_accounting_dept', 'brgy_txn_types', 'brgy_sop_hold_billing', 'brgy_sop_need_scans', 'brgy_sop_review_other'):
        t.check(a.finds(f'[name="{k}"]', sop), f'{k} on the SOP card')
    t.contains(sop.text, 'Tradenames', 'the tradenames are kept on the Tradenames list (v33)')
    t.check(a.find('#t_brgy_sop_hold_billing').is_selected(), 'the payment hold (special case F) is on')
    t.contains(a.value('brgy_txn_types'), 'cash_out|Cash Out (purchases)', 'Cash Out among the types')
    t.contains(a.value('brgy_txn_types'), 'kaliwaan|Kaliwaan (purchases)', 'Kaliwaan among the types')
    for k in ('brgy_signatory_title', 'brgy_signatory_mobile', 'brgy_manager_name', 'brgy_manager_position', 'brgy_liaison_name', 'brgy_liaison_mobile'):
        t.check(a.exists(f'[name="{k}"]', visible=True), f'{k} on the company card')
    lh = a.xfind("//div[contains(@class,'card')][.//div[contains(@class,'card-header')][contains(., 'Letterheads of the forms')]]")
    t.equal(len(a.finds('.lh-sample', lh)), 2, 'the company and the barangay letterheads are shown')
    t.check(a.finds('.lh-sample .fx-letterhead', lh), 'a letterhead drawn')
    submit_btn(a, 'Save settings')
    a.assert_healthy('Contracting Settings after saving')
    saved(a, 'Nothing changed')
    t.check(a.find('#t_brgy_sop_hold_billing').is_selected(), 'the payment hold is still on')
