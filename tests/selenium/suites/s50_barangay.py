"""Barangay Contracts: a contract through its twelve phases (procuring entity, opportunity, compliance checklist,
price schedule, bid approval, submission, award, delivery and acceptance, billing, collection, closeout), the
Approval Queue, the Document Vault, the set-up lists, the settings and the reports of the module.

Every project here follows the process "The twelve phases" (general). The SOP (Process tab, SOP Steps & Papers),
Forms & Proformas and Templates are tested elsewhere.

Who does what (accounts.py): bidofficer prepares (maker), admin checks (the named checker), manager approves
(the named approver), delivery and pmgr deliver and record the inspection, billing bills, collector collects.
"""
import os
import re

from selenium.webdriver.common.by import By
from selenium.webdriver.support import expected_conditions as EC
from selenium.webdriver.support.ui import WebDriverWait

from core import fixtures as fx
from core.runner import CheckFailed, suite

S = suite('barangay', 'Barangay Contracts', order=80)

CHECKER = 'System Administrator'      # admin: the named checker of the projects made here
APPROVER = 'Victor Aquino'            # manager: the named approver
PREPARER = 'Rosa Dizon'               # bidofficer: preparer and authorized submitter
PM = 'Leo Navarro'                    # pmgr
COLLECTOR = 'Paolo Ramos'             # collector
MODE = 'Direct Acquisition'
ABC = 50000.00
RETENTION = 10                        # % of each billing held by the entity


# ------------------------------------------------------------------------------------------- helpers
def money(v):
    return f'{v:,.2f}'


def mdy(iso):
    """2026-11-04 -> 11/04/2026 (the dates on the pages)"""
    y, m, d = iso.split('-')
    return f'{m}/{d}/{y}'


def num(text):
    """'49,500.00' -> 49500.0 ('(1.00)' is negative)"""
    t = (text or '').strip().replace(',', '')
    neg = t.startswith('(') and t.endswith(')')
    t = t.strip('()')
    try:
        return -float(t) if neg else float(t)
    except ValueError:
        raise CheckFailed(f'Not an amount: {text!r}')


def open_project(a, pid, tab=None):
    a.go('brgy.projects', a='view', id=pid, tab=tab)
    a.assert_healthy(f'project, tab {tab or "overview"}')


def current_id(a):
    return int(a.current_params().get('id') or 0)


def fig(a, label):
    """The value of a figure box (label, value) on the page."""
    for f in a.finds('.fig'):
        lab = f.find_elements(By.CSS_SELECTOR, '.fig-label')
        if lab and lab[0].text.strip().lower() == label.lower():
            return f.find_element(By.CSS_SELECTOR, '.fig-value').text.strip()
    raise CheckFailed(f'No figure {label!r} on the page')


def row_with(a, text, css='main table tr'):
    """The first visible table row holding text."""
    for r in a.finds(css):
        if a._shown(r) and text in r.text:
            return r
    raise CheckFailed(f'No row with {text!r} ({css})')


def dd(a, label):
    """The value next to a label of a details list (dt / dd)."""
    return a.xfind(f"//dt[normalize-space(.)='{label}']/following-sibling::dd[1]", timeout=3).text.strip()


def all_text(a):
    """The text of main, folded parts (details) included."""
    return a.js('return document.querySelector("main").textContent') or ''


def head_status(a):
    els = a.finds('.proj-head-status')
    return els[0].text if els else ''


def send_modal(a, button, fields=None, remarks=None, checks=None):
    """Clicks a button that opens a modal (data-bs-target), fills the modal and sends it; the page reloads."""
    target = button.get_attribute('data-bs-target')
    a.click(button)
    modal = a.wait_for(lambda: next((m for m in a.finds(target) if a._shown(m) and 'show' in (m.get_attribute('class') or '')), None),
                       f'the dialog {target} did not open')
    a.sleep(0.35)
    for k, v in (fields or {}).items():
        a.fill(k, v, root=modal)
    for el in (checks or []):
        a.fill(modal.find_element(By.CSS_SELECTOR, el), True)
    if remarks is not None:
        a.fill('remarks', remarks, root=modal)
    a.click_and_wait(modal.find_element(By.CSS_SELECTOR, '.modal-footer button:not([type="button"])'))
    a.assert_healthy(f'after {target}')
    return a


def step(a, label, remarks='', root=None):
    """A maker / checker / approver button (Send for review, Mark reviewed, Approve ...) and its dialog."""
    btn = a.by_text(label, tags='button', root=root, exact=True)
    return send_modal(a, btn, remarks=remarks)


def has_button(a, label, root=None):
    try:
        a.by_text(label, tags='button', root=root, exact=True, timeout=0.5)
        return True
    except CheckFailed:
        return False


def force_post(a, action, fields):
    """Sends a form the page does not offer (with the page's security token), as a user who tries
    a step without its button would. The page that comes back carries the system's answer."""
    old = a.driver.find_element(By.TAG_NAME, 'html')
    a.js("""const f = document.createElement('form'); f.method = 'post'; f.action = arguments[0];
            const add = (k, v) => { const i = document.createElement('input'); i.type = 'hidden'; i.name = k; i.value = v; f.appendChild(i); };
            add('_csrf', document.querySelector('input[name=_csrf]').value);
            Object.entries(arguments[1]).forEach(([k, v]) => add(k, String(v)));
            document.body.appendChild(f); HTMLFormElement.prototype.submit.call(f);""", action, fields)
    WebDriverWait(a.driver, a.timeout * 2).until(EC.staleness_of(old))
    a.wait_ready()


def queue_item(a, text):
    return a.xfind(f"//div[contains(@class,'queue-item')][contains(., '{text}')]", timeout=3)


def in_queue(a, text):
    return any(text in q.text for q in a.finds('.queue-item'))


def pdf(name):
    """A PDF whose content is unique to this run (the vault refuses the same file twice)."""
    return fx.pdf(f'brgy_{name}_{fx.stamp()}.pdf', f'{name} {fx.unique("Selenium")}')


def doc_id_in(el):
    """The vault id of the first document link inside el."""
    for link in el.find_elements(By.CSS_SELECTOR, 'a[href*="brgy.vault"]'):
        m = re.search(r'[?&]id=(\d+)', link.get_attribute('href') or '')
        if m:
            return int(m.group(1))
    raise CheckFailed('No document link in ' + el.text[:120])


_NO_PDF_JS = r"""(function () {
  const off = () => document.querySelectorAll('object[type="application/pdf"], embed[type="application/pdf"]').forEach((o) => {
    const d = document.createElement('div'); d.className = 'pdf-preview-off'; d.innerHTML = o.innerHTML; o.replaceWith(d); });
  new MutationObserver(off).observe(document, { childList: true, subtree: true });
})();"""


class pdf_frames_off:
    """While inside: the PDF preview of a vault page (an <object> of type application/pdf) is replaced by its
    fallback link before Chrome starts its PDF viewer. In a headless browser that viewer can hang the page
    ("Timed out receiving message from renderer"); the file itself is checked by downloading it (app.fetch)."""

    def __init__(self, a):
        self.a = a
        self.sid = None

    def __enter__(self):
        try:
            self.sid = self.a.driver.execute_cdp_cmd('Page.addScriptToEvaluateOnNewDocument', {'source': _NO_PDF_JS})['identifier']
        except Exception:
            self.sid = None
        return self

    def __exit__(self, *exc):
        if self.sid is not None:
            try:
                self.a.driver.execute_cdp_cmd('Page.removeScriptToEvaluateOnNewDocument', {'identifier': self.sid})
            except Exception:
                pass
        return False


def review_doc(a, doc_id, decision='approved'):
    """The vault review of a document (manager has the Document Vault Edit right)."""
    a.go('brgy.vault', a='view', id=doc_id)
    a.assert_healthy('vault document')
    a.click_and_wait(a.find(f'button[name=do][value={decision}]'))
    return a.expect_saved()


def waive_mandatory(a, pid, reason):
    """The approver marks every mandatory eligibility / bid paper not in order as not required."""
    open_project(a, pid, 'compliance')
    n = 0
    for _ in range(15):
        target = None
        for r in a.finds('table.checklist tr'):
            cls = r.get_attribute('class') or ''
            if not re.search(r'\bcl-(missing|expired|returned|pending)\b', cls):
                continue
            cells = r.find_elements(By.TAG_NAME, 'td')
            if len(cells) > 2 and cells[2].text.strip() == 'Mandatory':
                btns = r.find_elements(By.CSS_SELECTOR, 'button[data-bs-target="#mWaive"]')
                if btns:
                    target = btns[0]
                    break
        if not target:
            break
        send_modal(a, target, remarks=reason)
        a.expect_saved('not required')
        n += 1
    return n


def report_rows(a):
    return [r for r in a.finds('#rptTable tbody tr')
            if 'No data for these settings' not in r.text and 'total-row' not in (r.get_attribute('class') or '')]


# ------------------------------------------------------------------------------------------- the entity
@S.case('Add a procuring entity with its officials, and a new head closes the term of the one before')
def entity(t):
    a = t.app
    a.as_user('bidofficer')
    name = fx.unique('Barangay Selenium')
    a.go('brgy.entities', a='new')
    a.assert_healthy('new entity')
    a.fill_form({'official_name': name, 'entity_type': 'Barangay', 'code': fx.code('BS'), 'address': 'Purok 3, Rizal St.',
                 'barangay': 'Selenium', 'city_municipality': 'Talisay City', 'province': 'Cebu', 'tin': '111-222-333-000',
                 'phone': '(032) 555-0100', 'email': 'bac.selenium@example.com'})
    a.fill(a.find('input[name="submission_methods[]"][value="email"]'), True)
    a.submit()
    a.expect_saved('Procuring entity added')
    a.assert_healthy('entity')
    t.equal(a.h1(), name, 'the entity page')
    eid = current_id(a)
    t.check(eid > 0, 'the entity has an id')
    t.contains(a.main_text(), 'Hand-carried (physical), E-mail', 'submissions taken by')

    first, second = fx.unique('Hon. Ramon Selenium'), fx.unique('Hon. Liza Selenium')
    officials = [
        {'role_code': 'Punong Barangay', 'name': first, 'position': 'Punong Barangay', 'valid_from': '2025-06-30', 'is_signatory': True},
        {'role_code': 'BAC Chairperson', 'name': fx.unique('Kgd. Pedro Selenium'), 'position': 'BAC Chair', 'valid_from': '2025-06-30'},
        {'role_code': 'Treasurer', 'name': fx.unique('Maria Treasurer Selenium'), 'position': 'Barangay Treasurer', 'email': 'treasurer.selenium@example.com'},
    ]
    for o in officials:
        form = a.find('#contactForm')
        a.fill_form(o, root=form)
        a.submit(root=form)
        a.expect_saved('Saved')
        a.assert_healthy('official saved')
        t.contains(row_with(a, o['name']).text, o['position'], 'the official in the list')
    t.contains(row_with(a, first).text, 'signatory', 'the signatory mark')

    # a "valid to" before the "valid from" is refused
    form = a.find('#contactForm')
    a.fill_form({'role_code': 'Secretary', 'name': fx.unique('Wrong Dates'), 'valid_from': fx.today(), 'valid_to': fx.today(-10)}, root=form)
    a.submit(root=form)
    a.expect_refused('cannot be before')

    # a new Punong Barangay from today: the one before served until yesterday
    form = a.find('#contactForm')
    a.fill_form({'role_code': 'Punong Barangay', 'name': second, 'position': 'Punong Barangay', 'valid_from': fx.today()}, root=form)
    a.submit(root=form)
    a.expect_saved('closes the term')
    old = row_with(a, first)
    t.contains(old.text, f'{mdy("2025-06-30")} to {mdy(fx.today(-1))}', 'the term of the former head')
    t.check('text-muted' in (old.get_attribute('class') or ''), 'the former head is shown as past')
    t.contains(row_with(a, second).text, f'{mdy(fx.today())} to now', 'the new head')

    # the directory shows the entity with its current head; an edit is kept
    a.go('brgy.entities', q=name)
    a.assert_healthy('entities list')
    r = row_with(a, name)
    t.contains(r.text, second, 'the head in the list')
    t.contains(r.text, 'Talisay City, Cebu', 'the location in the list')
    a.go('brgy.entities', a='edit', id=eid)
    a.fill('phone', '(032) 555-0199')
    a.submit()
    a.expect_saved('Saved')
    t.contains(a.main_text(), '(032) 555-0199', 'the changed phone')
    t.state.update(entity=name, entity_id=eid)


# ------------------------------------------------------------------------------------------- the opportunity
def new_project(a, t, title, abc, checker=CHECKER, approver=APPROVER, retention=RETENTION):
    a.go('brgy.projects', a='new')
    a.assert_healthy('new project')
    a.fill(a.find('input[name=process][value=general]'), True)
    a.fill_form({'title': title, 'entity_id': t.state['entity'], 'category': 'Goods', 'mode_id': MODE,
                 'reference_no': 'RFQ-' + fx.code('S'), 'posting_date': fx.today(), 'abc_amount': f'{abc:.2f}',
                 'funding_source': 'General Fund', 'end_user': 'Office of the Punong Barangay', 'delivery_site': 'Barangay Hall',
                 'delivery_days': '15', 'prebid_at': f'{fx.today(2)}T10:00', 'bid_deadline': f'{fx.today(7)}T17:00',
                 'bid_opening_at': f'{fx.today(7)}T17:30', 'retention_pct': str(retention), 'ld_rate_pct': '0',
                 'checker_id': checker, 'approver_id': approver, 'submitter_id': PREPARER, 'pm_id': PM, 'collector_id': COLLECTOR})
    a.submit()


@S.case('Record a new opportunity with its ABC and dates; one person as checker and approver is refused', needs=['entity'])
def opportunity(t):
    a = t.app
    a.as_user('bidofficer')
    title = fx.unique('Selenium supply of office furniture')
    new_project(a, t, title, ABC, checker=APPROVER, approver=APPROVER)
    a.expect_refused('cannot be the checker and the approver')
    a.assert_healthy('refused project form')
    t.equal(a.value('title'), title, 'the title is kept on the form')
    a.fill('checker_id', CHECKER)
    a.submit()
    a.expect_saved('Opportunity recorded')
    a.assert_healthy('project page')
    pno = a.h1()
    pid = current_id(a)
    t.check(re.match(r'^[A-Z]+-\d+', pno), f'a project number ({pno})')
    t.equal(fig(a, 'Approved budget (ABC)'), money(ABC), 'the ABC')
    text = a.main_text()
    for part in (title, t.state['entity'], 'Opportunity intake', 'Bid deadline', mdy(fx.today(7)), 'Pre-bid conference'):
        t.contains(text, part, 'the project overview')
    for label, want in (('Checker', CHECKER), ('Approver', APPROVER), ('Authorized submitter', PREPARER), ('Project manager', PM),
                        ('Retention', f'{RETENTION:.2f}%'), ('Delivery period', '15 calendar days')):
        t.equal(dd(a, label), want, label)

    a.go('brgy.projects', q=title)
    a.assert_healthy('projects list')
    r = row_with(a, pno)
    for part in (title, t.state['entity'], 'Opportunity intake', money(ABC)):
        t.contains(r.text, part, 'the row in Projects & Contracts')

    open_project(a, pid)
    send_modal(a, a.find('button[data-bs-target="#mGo"]'), remarks='Office furniture is in our product line')
    a.expect_saved('moves to eligibility')
    t.contains(head_status(a), 'Eligibility and compliance', 'the phase after the go decision')
    t.state.update(pid=pid, pno=pno, title=title)


# ------------------------------------------------------------------------------------------- compliance
@S.case('Answer the compliance checklist from the vault: a company paper with validity, reviewed by another person', needs=['pid'])
def compliance(t):
    a = t.app
    with pdf_frames_off(a):
        pid, pno = t.state['pid'], t.state['pno']
        a.as_user('bidofficer')
        open_project(a, pid, 'compliance')
        req = fx.unique('Company profile with validity')
        send_modal(a, a.find('button[data-bs-target="#mReqAdd"]'),
                   fields={'name': req, 'stage': 'Eligibility', 'requirement_type': 'Mandatory', 'doc_type': 'Company profile',
                           'needs_validity': True, 'legal_basis': 'RFQ clause 4 (Selenium)'})
        a.expect_saved('Requirement added')
        r = row_with(a, req, 'table.checklist tr')
        t.contains(r.text, 'Missing', 'the new requirement')

        # the company paper, uploaded with its validity date, filed as a company paper
        valid = fx.today(200)
        send_modal(a, r.find_element(By.CSS_SELECTOR, 'button[data-bs-target="#mAttach"]'),
                   fields={'file': pdf('company_profile'), 'title': req, 'doc_no': fx.code('CP'), 'issued_by': 'The company', 'valid_until': valid, 'as_company': True})
        a.expect_saved('Document filed for the requirement')
        r = row_with(a, req, 'table.checklist tr')
        t.contains(r.text, 'For review', 'filed, waiting for the review')
        t.contains(r.text, '(company)', 'filed as a company paper')
        t.contains(r.text, f'valid until {mdy(valid)}', 'its validity')
        company_doc = doc_id_in(r)

        # a paper of the project (Documents tab) and one filed from the vault answer their requirements
        open_project(a, pid, 'documents')
        form = a.form_with('confidential')
        a.fill_form({'file': pdf('price_schedule'), 'doc_type': 'Price schedule / bill of quantities', 'title': 'Price schedule ' + pno}, root=form)
        a.submit(root=form)
        msg = a.expect_saved('waits for review')
        sched_doc = int(re.search(r'#(\d+)', msg).group(1))
        t.contains(row_with(a, 'Price schedule ' + pno).text, 'For review', 'the document on the Documents tab')

        a.go('brgy.vault', tab='all')
        a.assert_healthy('vault, all documents')
        form = a.find('form[action*="a=upload"]')
        a.fill_form({'file': pdf('quotation'), 'doc_type': 'Price quotation / bid form', 'project_id': pno, 'title': 'Signed quotation ' + pno,
                     'doc_no': fx.code('Q')}, root=form)
        a.submit(root=form)
        msg = a.expect_saved('Filed')
        quote_doc = int(re.search(r'#(\d+)', msg).group(1))

        # the preparer cannot review what she filed
        a.go('brgy.vault', a='view', id=company_doc)
        a.assert_healthy('vault document')
        t.check(not a.exists('button[name=do][value=approved]', visible=True), 'no review buttons for the person who filed it')
        t.contains(a.main_text(), 'Waiting for review by another person', 'the note on the document')

        # the manager reviews the three papers in the vault
        a.as_user('manager')
        a.go('brgy.vault', tab='review')
        a.assert_healthy('vault, for review')
        for title in (req, 'Price schedule ' + pno, 'Signed quotation ' + pno):
            t.contains(a.main_text(), title, 'in the review list')
        for d in (company_doc, sched_doc, quote_doc):
            review_doc(a, d)
        a.go('brgy.vault', tab='company')
        a.assert_healthy('vault, company papers')
        t.contains(a.main_text(), 'Company profile', 'the kind of paper among the company papers')
        a.go('brgy.vault', tab='all', q=req)
        a.assert_healthy('vault, search')
        r = row_with(a, req, '#docTable tbody tr')
        for part in ('Company profile', 'Company', mdy(valid), 'Approved'):
            t.contains(r.text, part, 'the company paper in the vault')

        open_project(a, pid, 'compliance')
        for name in (req, 'Price schedule / bill of quantities', 'Signed price quotation'):
            t.contains(row_with(a, name, 'table.checklist tr').text, 'In order', f'checklist: {name}')

        # what the RFQ does not ask is waived by the approver, with the reason
        n = waive_mandatory(a, pid, 'Not asked in the RFQ (Selenium)')
        t.check(n >= 1, 'mandatory papers waived by the approver')
        t.check('mandatory not in order' not in a.main_text(), 'no mandatory paper left out of order')
        t.state.update(company_doc=company_doc, company_req=req)


# ------------------------------------------------------------------------------------------- price schedule
def fill_line(a, tr, values):
    for k, v in values.items():
        a.fill(tr.find_element(By.CSS_SELECTOR, f'[data-k="{k}"]'), v)


@S.case('Price schedule with costs and markup; the ABC meter; a bid above the ABC is refused', needs=['pid'])
def price_schedule(t):
    a = t.app
    pid = t.state['pid']
    a.as_user('bidofficer')
    open_project(a, pid, 'lines')
    rows = a.finds('#linesGrid tbody tr')
    t.check(len(rows) >= 2, 'empty lines to fill')
    chair, cabinet = fx.unique('Ergonomic office chair'), fx.unique('Steel filing cabinet')
    fill_line(a, rows[0], {'line_kind': 'Goods', 'description': chair, 'unit': 'pc', 'qty': '10', 'unit_cost': '3000', 'markup_pct': '25'})
    fill_line(a, rows[1], {'line_kind': 'Goods', 'description': cabinet, 'unit': 'unit', 'qty': '5', 'unit_cost': '2000', 'unit_price': '2600'})
    a.sleep(0.3)
    t.equal(num(rows[0].find_element(By.CSS_SELECTOR, '[data-k="amount"]').text), 37500.0, 'cost 3,000 plus 25% markup, 10 pieces')
    t.equal(num(a.find('#lgTotal').text), 50500.0, 'the total of the grid')
    t.check('is-over' in a.find('.abc-meter').get_attribute('class'), 'the ABC meter shows the bid above the ABC')
    t.contains(a.find('#abcText').text, 'above the ABC', 'the ABC meter text')
    a.submit(root=a.find('#linesForm'))
    a.expect_saved('Price schedule saved')
    t.contains(head_status(a), 'Costing and proposal', 'the phase after the price schedule')

    # the bid above the ABC does not go for review (Contracting Settings: a bid above the ABC is blocked)
    t.contains(all_text(a), 'is above the approved budget', 'the reason shown before sending')
    step(a, 'Send for review', 'Ready (above the ABC on purpose)')
    a.expect_refused('above the approved budget')

    # corrected under the ABC
    open_project(a, pid, 'lines')
    rows = a.finds('#linesGrid tbody tr')
    t.equal(rows[0].find_element(By.CSS_SELECTOR, '[data-k="description"]').get_attribute('value'), chair, 'the saved first line')
    fill_line(a, rows[1], {'unit_price': '2400'})
    a.sleep(0.3)
    t.equal(num(a.find('#lgTotal').text), 49500.0, 'the corrected total')
    t.check('is-over' not in a.find('.abc-meter').get_attribute('class'), 'the meter is under the ABC')
    t.contains(a.find('#abcText').text, '(99.0%)', 'the share of the ABC')
    a.submit(root=a.find('#linesForm'))
    a.expect_saved('Price schedule saved')
    open_project(a, pid)
    t.equal(fig(a, 'Bid amount'), money(49500), 'the bid amount')
    est = fig(a, 'Estimated cost')
    t.equal(est, money(40000), 'the estimated cost (10 x 3,000 + 5 x 2,000)')
    t.state.update(chair=chair, cabinet=cabinet, bid=49500.0)


# ------------------------------------------------------------------------------------------- bid approval
@S.case('Send the bid for review: the named checker reviews, the approver approves from the Approval Queue', needs=['bid'])
def bid_approval(t):
    a = t.app
    pid, pno = t.state['pid'], t.state['pno']
    a.as_user('bidofficer')
    open_project(a, pid)
    step(a, 'Send for review', 'Costing checked, papers in order')
    a.expect_saved('sent for review')
    t.contains(head_status(a), 'Pre-submission approval', 'the phase in approval')
    t.check(not has_button(a, 'Mark reviewed') and not has_button(a, 'Approve'), 'the preparer gets no review or approve button')

    # the approver cannot review in place of the named checker
    a.as_user('manager')
    open_project(a, pid)
    t.check(not has_button(a, 'Mark reviewed'), 'no review button for the approver')
    t.contains(a.main_text(), f'Only {CHECKER} (named checker of this project)', 'why the approver cannot review')
    a.go('brgy.approvals')
    t.check(not in_queue(a, pno), 'not yet in the approver\'s queue')

    # the checker finds it in the Approval Queue and reviews it there
    a.as_user('admin')
    a.go('brgy.approvals')
    a.assert_healthy('approval queue')
    item = queue_item(a, pno)
    t.contains(item.text, money(49500), 'the amount in the queue')
    t.contains(item.text, 'For review', 'the status in the queue')
    step(a, 'Mark reviewed', 'Prices checked against the canvass', root=item)
    a.expect_saved('Marked reviewed')
    t.check(not in_queue(a, pno), 'gone from the checker\'s queue')
    open_project(a, pid)
    t.check(not has_button(a, 'Approve'), 'the checker gets no approve button')
    t.contains(a.main_text(), 'You reviewed this record; the approval has to come from another person', 'why the checker cannot approve')

    a.as_user('manager')
    a.go('brgy.approvals')
    a.assert_healthy('approval queue')
    item = queue_item(a, pno)
    t.contains(item.text, 'Reviewed', 'reviewed, waiting for the approval')
    step(a, 'Approve', 'Go', root=item)
    a.expect_saved('Approved')
    t.check(not in_queue(a, pno), 'gone from the approver\'s queue')

    a.as_user('bidofficer')
    open_project(a, pid, 'lines')
    t.contains(a.main_text(), 'the schedule is read-only', 'the price schedule after the approval')
    t.check(not a.exists('#linesGrid'), 'no editable grid')
    t.contains(a.main_text(), 'Submit the approved bid', 'the next step')
    t.contains(a.main_text(), f'Authorized submitter: {PREPARER}', 'the authorized submitter')
    t.state['approved'] = True


@S.case('An approver cannot approve a bid they sent for review themselves', needs=['entity'])
def own_bid(t):
    a = t.app
    a.as_user('bidofficer')
    title = fx.unique('Selenium supply of bond paper')
    new_project(a, t, title, 10000)
    a.expect_saved('Opportunity recorded')
    pid, pno = current_id(a), a.h1()
    send_modal(a, a.find('button[data-bs-target="#mGo"]'), remarks='Small order, in stock')
    a.expect_saved('moves to eligibility')
    open_project(a, pid, 'lines')
    fill_line(a, a.finds('#linesGrid tbody tr')[0], {'description': 'Bond paper, A4, 70 gsm', 'unit': 'ream', 'qty': '20', 'unit_cost': '180', 'unit_price': '240'})
    a.submit(root=a.find('#linesForm'))
    a.expect_saved('Price schedule saved')

    a.as_user('manager')
    waive_mandatory(a, pid, 'Not asked for this purchase (Selenium)')
    open_project(a, pid)
    step(a, 'Send for review', 'Sent by the approver himself')
    a.expect_saved('sent for review')
    a.as_user('admin')
    open_project(a, pid)
    step(a, 'Mark reviewed', 'Checked')
    a.expect_saved('Marked reviewed')

    a.as_user('manager')
    open_project(a, pid)
    t.check(not has_button(a, 'Approve'), 'no approve button for the one who sent it')
    t.contains(a.main_text(), 'You prepared this record; another person has to approve it', 'the reason on the page')
    a.go('brgy.approvals')
    t.check(not in_queue(a, pno), 'not in his own Approval Queue')
    # the approval forced without the button is refused by the system (sent from a tab with a form, for its token)
    open_project(a, pid, 'tasks')
    force_post(a, a.url('brgy.projects', a='view', id=pid, tab='overview'), {'do': 'bid_approve', 'tab': 'overview', 'remarks': 'approving my own'})
    a.expect_refused('You prepared this record')
    t.contains(a.main_text(), 'Bid in approval', 'the bid is still in approval')

    # the checker sends it back so it does not wait in anyone's queue
    a.as_user('admin')
    open_project(a, pid)
    step(a, 'Return for correction', 'Approved by someone other than its preparer only (Selenium)')
    a.expect_saved('returned to the preparer')


# ------------------------------------------------------------------------------------------- submission and award
@S.case('Only the authorized submitter records the bid submission', needs=['approved'])
def submission(t):
    a = t.app
    pid = t.state['pid']
    fields = {'method': 'Hand-carried (physical)', 'recipient_name': 'BAC Secretariat', 'received_by': 'Ana BAC Selenium', 'ack_ref': 'LOG-' + fx.code('L')}
    a.as_user('pmgr')
    a.go('brgy.projects', a='view', id=pid, tab='submissions', sub='bid')
    a.assert_healthy('submissions tab')
    form = a.find('#subForm')
    t.equal(a.selected_text(a.field('sub_type', root=form)), 'Bid / quotation', 'what is submitted')
    a.fill_form(fields, root=form)
    a.submit(root=form)
    a.expect_refused(f'Only the authorized submitter, {PREPARER}')

    a.as_user('bidofficer')
    a.go('brgy.projects', a='view', id=pid, tab='submissions', sub='bid')
    form = a.find('#subForm')
    a.fill_form(dict(fields, proof=pdf('receiving_copy')), root=form)
    a.submit(root=form)
    a.expect_saved('Submission recorded')
    r = row_with(a, 'Bid / quotation')
    for part in ('Hand-carried (physical)', 'Ana BAC Selenium', 'proof'):
        t.contains(r.text, part, 'the submission in the list')
    t.contains(head_status(a), 'Bid submitted', 'the phase')
    t.state['submitted'] = True


@S.case('Record the award from the purchase order, a performance security, and start fulfilment', needs=['submitted'])
def award(t):
    a = t.app
    pid = t.state['pid']
    po = 'PO-' + fx.code('P')
    a.as_user('bidofficer')
    open_project(a, pid, 'award')
    t.equal(num(a.value('contract_amount')), 49500.0, 'the amount awarded comes from the approved schedule')
    vals = {'noa_ref': po, 'noa_date': fx.today(), 'noa_received_date': fx.today(), 'po_no': po, 'evidence_type': 'Purchase order / job order'}
    a.fill_form(vals)
    a.submit(root=a.form_with('noa_ref'))
    a.expect_refused('Attach the notice of award')
    a.fill_form(dict(vals, evidence=pdf('purchase_order')))
    a.submit(root=a.form_with('noa_ref'))
    a.expect_saved('Award recorded')
    t.contains(head_status(a), 'Awarded', 'the status')
    t.contains(head_status(a), 'Award and contract', 'the phase')
    t.contains(a.main_text(), f'Notice of award {po}', 'the notice on the Award tab')
    t.contains(a.main_text(), 'Amount awarded 49,500.00', 'the amount awarded')

    send_modal(a, a.find('button[data-bs-target="#mSec"]'),
               fields={'security_type': 'Performance security', 'form': 'Surety bond', 'amount': '4950', 'issuer': 'Selenium Surety Corp.',
                       'reference_no': 'SB-' + fx.code('B'), 'valid_until': fx.today(120)})
    a.expect_saved('Security recorded')
    r = row_with(a, 'Performance security')
    for part in ('Surety bond', '4,950.00', mdy(fx.today(120))):
        t.contains(r.text, part, 'the security')

    a.click_and_wait(a.by_text('Start fulfilment', tags='button', exact=True))
    a.expect_saved('Fulfilment started')
    t.contains(head_status(a), 'Mobilization and fulfilment', 'the phase')
    open_project(a, pid, 'ledger')
    t.contains(a.main_text(), 'Nothing posted yet', 'the award posts nothing to the ledger')
    t.state.update(awarded=True, po=po)


# ------------------------------------------------------------------------------------------- the vault
@S.case('Document Vault: a new version of a company paper, its download, and its review', needs=['company_doc', 'submitted'])
def vault(t):
    a = t.app
    with pdf_frames_off(a):
        old_id, req = t.state['company_doc'], t.state['company_req']
        a.as_user('bidofficer')
        a.go('brgy.vault', a='view', id=old_id)
        a.assert_healthy('vault document')
        form = a.form_with('issue_date')
        valid = fx.today(365)
        a.fill_form({'file': pdf('company_profile_v2'), 'issue_date': fx.today(), 'valid_until': valid, 'remarks': 'Renewed (Selenium)'}, root=form)
        a.click_and_wait(a.by_text('File the new version', tags='button', root=form))
        a.assert_healthy('new version')
        new_id = current_id(a)
        t.check(new_id and new_id != old_id, 'the new version has its own page')
        t.equal(fig(a, 'Version'), '2', 'version 2')
        t.equal(fig(a, 'Valid until'), mdy(valid), 'the new validity')
        t.contains(row_with(a, 'v1').text, 'Superseded', 'version 1 superseded')
        t.contains(row_with(a, 'v2').text, 'For review', 'version 2 waits for review')

        # the file downloads (and opens in the browser)
        status, headers, body = a.fetch(a.url('brgy.vault', a='download', id=new_id))
        t.equal(status, 200, 'download status')
        t.check(body[:5] == b'%PDF-', 'the download is the PDF')
        t.contains(headers.get('content-disposition', ''), 'attachment', 'sent as a download')
        status, headers, body = a.fetch(a.url('brgy.vault', a='file', id=new_id))
        t.equal(status, 200, 'open status')
        t.contains(headers.get('content-disposition', ''), 'inline', 'a PDF opens in the browser')

        # the reviewer approves the new version; the checklist moved to it
        a.as_user('manager')
        review_doc(a, new_id)
        t.contains(a.main_text(), 'Reviewed by Victor Aquino', 'the reviewer on the document')
        open_project(a, t.state['pid'], 'compliance')
        r = row_with(a, req, 'table.checklist tr')
        t.contains(r.text, 'v2', 'the checklist uses version 2')
        t.contains(r.text, 'In order', 'and it is in order')


# ------------------------------------------------------------------------------------------- delivery
@S.case('Deliver the goods (more than the contract is refused) and record the entity\'s inspection', needs=['awarded'])
def delivery(t):
    a = t.app
    pid, pno = t.state['pid'], t.state['pno']
    a.as_user('delivery')
    a.go('brgy.deliveries', a='new', project_id=pid)
    a.assert_healthy('new delivery')
    qtys = a.finds('#dlvLines input[name$="[qty]"]')
    t.equal(len(qtys), 2, 'the two contract lines')
    a.fill(qtys[0], '12')
    a.fill_form({'received_by_name': 'Barangay Secretary Selenium', 'received_position': 'Barangay Secretary', 'vehicle': 'ABC 1234'})
    a.submit()
    a.expect_refused('12 is more than the 10 left on the contract')
    qtys = a.finds('#dlvLines input[name$="[qty]"]')
    a.fill(qtys[0], '10')
    a.fill(qtys[1], '5')
    a.submit()
    a.expect_saved('Delivery recorded')
    a.assert_healthy('delivery')
    did = current_id(a)
    dr = a.h1().split(' ')[0]
    t.equal(fig(a, 'Value delivered'), money(10 * 3750 + 5 * 2400), 'the value delivered')

    # the entity's inspection (project manager): 1 cabinet rejected
    a.as_user('pmgr')
    a.go('brgy.deliveries', a='view', id=did)
    a.assert_healthy('delivery view')
    form = a.form_with('acceptance_ref')
    acc = a.finds('#inspLines input[name$="[accepted]"]')
    rej = a.finds('#inspLines input[name$="[rejected]"]')
    iar = 'IAR-' + fx.code('I')
    a.fill_form({'acceptance_ref': iar, 'inspectors': 'Inspection Committee Selenium'}, root=form)
    a.fill(acc[0], '10')
    a.fill(acc[1], '4')
    a.fill(rej[1], '1')
    a.submit(root=form)
    a.expect_refused('Attach the signed inspection and acceptance report')
    form = a.form_with('acceptance_ref')
    a.fill_form({'acceptance_ref': iar, 'inspectors': 'Inspection Committee Selenium', 'evidence': pdf('iar'),
                 'punch_list': 'One cabinet dented: for replacement'}, root=form)
    acc = a.finds('#inspLines input[name$="[accepted]"]')
    rej = a.finds('#inspLines input[name$="[rejected]"]')
    a.fill(acc[0], '10')
    a.fill(acc[1], '4')
    a.fill(rej[1], '1')
    a.submit(root=form)
    a.expect_saved('Inspection and acceptance recorded')
    t.equal(fig(a, 'Status'), 'Partial', 'partly accepted')
    t.equal(fig(a, 'Acceptance'), iar, 'the report number')

    open_project(a, pid, 'delivery')
    r = row_with(a, t.state['cabinet'])
    cells = [c.text.strip() for c in r.find_elements(By.TAG_NAME, 'td')]
    t.equal(cells[1:7], ['5', '5', '1', '4', '1', '0'], 'cabinets: contract, delivered, rejected, accepted, left, billed')
    t.contains(row_with(a, dr).text, iar, 'the delivery with its acceptance')
    t.contains(head_status(a), 'Delivery and acceptance', 'the phase')
    a.go('brgy.deliveries', q=pno)
    a.assert_healthy('deliveries list')
    t.contains(row_with(a, dr).text, iar, 'the delivery in Deliveries & Acceptance')
    t.state.update(delivered=True, dr=dr, delivery_id=did)


@S.case('The dashboard shows the alerts, the pipeline and the contract in force', needs=['delivered'])
def dashboard(t):
    a = t.app
    pid, pno, cabinet = t.state['pid'], t.state['pno'], t.state['cabinet']
    a.as_user('manager')
    a.go('brgy.dashboard')
    a.assert_healthy('dashboard')
    for label in ('Opportunities in preparation', 'Contracts in force', 'Still to be accepted', 'Receivable (ledger)'):
        fig(a, label)
    lines = [x.text for x in a.finds('.alert-list .alert-line')]
    t.check(lines, 'alerts under Needs attention')
    count = int(a.xfind("//div[contains(@class,'card-header')][contains(., 'Needs attention')]//span[contains(@class,'text-muted')]").text)
    mine = [x for x in lines if pno in x]
    if count <= 40:
        t.check(any('Rejected, not yet replaced' in x for x in mine), f'the rejected cabinet among the alerts ({count})')
    pipe = row_with(a, 'Delivery and acceptance', '.pipe-row')
    t.check(int((pipe.find_element(By.CSS_SELECTOR, '.pipe-n').text or '0')) >= 1, 'the pipeline counts the contract in delivery')
    t.contains(row_with(a, pno).text, money(49500), 'the contract in force with its amount')
    # the pipeline row opens the projects of that phase
    a.click_and_wait(pipe)
    a.assert_healthy('projects of a phase')
    t.contains(row_with(a, pno).text, 'Delivery and acceptance', 'the project under its phase')
    # the alerts of the project itself
    open_project(a, pid)
    t.contains(a.main_text(), f'Rejected, not yet replaced: 1 of {cabinet}', 'the alert on the project')


# ------------------------------------------------------------------------------------------- billing
@S.case('Bill the accepted quantities (more than accepted is refused): checked, approved, invoiced', needs=['delivered'])
def billing(t):
    a = t.app
    pid = t.state['pid']
    si = 'SI' + fx.code('N')
    a.as_user('billing')
    a.go('brgy.billing', a='new', project_id=pid)
    a.assert_healthy('new billing pack')
    q = a.finds('#billLines input[name^="lines["]')
    t.equal(len(q), 2, 'the two lines')
    a.fill('si_no', si)
    a.fill(q[0], '10')
    a.fill(q[1], '5')
    a.submit()
    a.expect_refused('only 4 accepted and not yet billed')
    q = a.finds('#billLines input[name^="lines["]')
    a.fill(q[1], '4')
    a.sleep(0.2)
    gross = 10 * 3750 + 4 * 2400
    t.equal(num(a.find('#billTotal').text), float(gross), 'the total of the billing')
    a.submit()
    a.expect_saved('Billing pack prepared as a draft')
    a.assert_healthy('billing')
    bid = current_id(a)
    bno = a.h1().replace('Billing ', '')
    t.equal(fig(a, 'Amount billed'), money(gross), 'the amount billed')
    t.equal(dd(a, 'Retention'), money(gross * RETENTION / 100), 'the retention expected')

    # the same sales invoice number a second time is refused
    a.go('brgy.billing', a='new', project_id=pid)
    a.fill('si_no', si)
    a.submit()
    a.expect_refused(f'Sales invoice number {si} is already used on billing {bno}')

    a.go('brgy.billing', a='view', id=bid)
    step(a, 'Send for review', 'Pack ready')
    a.expect_saved('Sent for review')
    t.check(not has_button(a, 'Mark reviewed'), 'the billing officer cannot review her own billing')
    a.as_user('admin')
    a.go('brgy.billing', a='view', id=bid)
    step(a, 'Mark reviewed', 'Quantities agree with the IAR')
    a.expect_saved('Marked reviewed')
    a.as_user('manager')
    a.go('brgy.approvals')
    item = queue_item(a, bno)
    t.contains(item.text, money(gross), 'the billing in the approver\'s queue')
    step(a, 'Approve', 'OK to invoice', root=item)
    a.expect_saved('Approved')

    a.as_user('billing')
    a.go('brgy.billing', a='view', id=bid)
    a.click_and_wait(a.by_text('Issue the invoice', tags='button', exact=True))
    msg = a.expect_saved('posted to the Customer:Job')
    t.equal(fig(a, 'Status'), 'Invoiced', 'the billing is invoiced')
    t.equal(fig(a, 'Open on the invoice'), money(gross), 'the invoice is open for the whole amount')
    t.check(not has_button(a, 'Issue the invoice'), 'no second invoice offered')
    open_project(a, pid, 'billing')
    t.equal(fig(a, 'Billed'), money(gross), 'billed on the contract')
    t.equal(fig(a, 'Receivable (ledger)'), money(gross), 'receivable in the ledger')
    t.contains(row_with(a, bno).text, 'Invoiced', 'the billing on the contract')
    t.state.update(billing_id=bid, billing_no=bno, gross=float(gross), invoice_msg=msg)


# ------------------------------------------------------------------------------------------- collection
@S.case('Collect with the taxes withheld and the retention; the 2307 tracker and the retention tracker show it', needs=['billing_id'])
def collection(t):
    a = t.app
    pid, bid, gross = t.state['pid'], t.state['billing_id'], t.state['gross']
    a.as_user('collector')
    a.go('brgy.collections', a='new', billing_id=bid)
    a.assert_healthy('new collection')
    a.click(a.find('#fillExpected'))
    a.sleep(0.2)
    t.equal(num(a.find('#colSettled').text), gross, 'paid in full as expected')
    t.contains(a.find('#colLeft').text, 'Settles the billing in full', 'the calculator')
    ewt, vat, ret = num(a.value('ewt_amount')), num(a.value('vatwh_amount')), num(a.value('retention_amount'))
    t.equal(ret, round(gross * RETENTION / 100, 2), 'the retention of the billing')
    net = round(gross / 1.12, 2)
    t.check(abs(ewt - round(net * 0.01, 2)) < 0.02, f'income tax withheld 1% of the amount net of VAT ({ewt})')
    t.check(abs(vat - round(net * 0.05, 2)) < 0.02, f'VAT withheld 5% of the amount net of VAT ({vat})')
    check_no = fx.code('CK')
    a.fill_form({'check_no': check_no, 'check_date': fx.today(), 'receipt_no': 'AR-' + fx.code('R'), 'ewt_amount': '100.00'})
    a.fill('cert_2307', True)
    a.submit()
    a.expect_refused('differ from the Withholding Tax Table')
    a.click(a.find('#fillExpected'))
    a.fill_form({'check_no': check_no, 'check_date': fx.today()})
    a.fill('cert_2307', True)
    a.submit()
    a.expect_saved('posted')
    a.assert_healthy('collection')
    cno = a.h1().replace('Collection ', '')
    cash = round(gross - ewt - vat - ret, 2)
    t.equal(fig(a, 'Cash received'), money(cash), 'the cash received')
    t.equal(fig(a, 'Settled on the billing'), money(gross), 'settled on the billing')
    t.equal(fig(a, 'Taxes withheld'), money(ewt + vat), 'the taxes withheld')
    t.equal(fig(a, 'BIR Form 2307'), 'Received', 'the 2307 received')

    open_project(a, pid, 'billing')
    t.equal(fig(a, 'Receivable (ledger)'), money(0), 'nothing left receivable')
    t.equal(fig(a, 'Retention held'), money(ret), 'the retention held')
    t.equal(fig(a, 'Taxes withheld'), money(ewt + vat), 'the taxes withheld on the contract')
    t.contains(row_with(a, cno).text, 'received', 'the 2307 on the collection')

    # the reports of the taxes withheld and of the retention
    a.as_user('manager')
    a.go('rpt.brgy_wht_credits', dates='all', entity_id=t.state['entity_id'])
    a.assert_healthy('2307 tracker')
    r = row_with(a, t.state['pno'], '#rptTable tbody tr')
    for part in (money(ewt), money(vat)):
        t.contains(r.text, part, 'the collection in the 2307 tracker')
    a.go('rpt.brgy_retention', entity_id=t.state['entity_id'])
    a.assert_healthy('retention tracker')
    r = row_with(a, t.state['pno'], '#rptTable tbody tr')
    t.contains(r.text, money(ret), 'the retention held in the tracker')
    t.contains(r.text, 'Performance security 4,950.00 active', 'the security in the tracker')
    t.state.update(collected=True, retention=ret)


# ------------------------------------------------------------------------------------------- closeout
@S.case('Close out the contract with the open points explained', needs=['collected'])
def closeout(t):
    a = t.app
    pid = t.state['pid']
    a.as_user('manager')
    open_project(a, pid)
    btn = a.find('button[data-bs-target="#mClose"]')
    a.click(btn)
    modal = a.wait_for(lambda: next((m for m in a.finds('#mClose') if a._shown(m)), None), 'the closeout dialog')
    a.sleep(0.35)
    text = modal.text
    t.contains(text, 'Open points', 'the closeout lists open points')
    t.contains(text, 'retention is still held', 'retention still held')
    t.contains(text, '4 of 5 accepted', 'the cabinet not replaced')
    t.check('Not yet' not in text, 'nothing blocks the closeout')
    a.click(modal.find_element(By.CSS_SELECTOR, '.btn-close'))
    a.sleep(0.4)
    # the open points have to be confirmed
    send_modal(a, a.find('button[data-bs-target="#mClose"]'), remarks='Closing (Selenium)')
    a.expect_refused('Confirm the closeout')
    send_modal(a, a.find('button[data-bs-target="#mClose"]'), checks=['input[name=override]'],
               remarks='Retention to be released after the warranty; the entity waived the replacement cabinet (Selenium)')
    a.expect_saved('Contract closed out')
    t.contains(head_status(a), 'Closed', 'the status')
    t.contains(head_status(a), 'Closeout', 'the phase')
    t.check(len(a.finds('.phase-track li.pt-done')) == 12, 'all twelve phases done')
    a.go('brgy.projects', q=t.state['title'], status='closed')
    t.contains(row_with(a, t.state['pno']).text, 'Closed', 'in the closed projects')
    t.state['closed'] = True


# ------------------------------------------------------------------------------------------- set-up lists
@S.case('Requirement library: a requirement proposed by the bid officer, approved and edited by the approver', needs=['entity'])
def requirements(t):
    a = t.app
    name = fx.unique('Barangay clearance of the supplier')
    code = 'REQ-' + fx.code('SEL', 14)
    a.as_user('bidofficer')
    a.go('brgy.requirements', a='new')
    a.assert_healthy('new requirement')
    a.fill_form({'name': name, 'code': code, 'stage': 'Eligibility', 'requirement_type': 'Optional', 'doc_group': 'Legal',
                 'doc_type': 'Other document', 'entity_id': t.state['entity'], 'legal_basis': 'Barangay ordinance (Selenium)',
                 'description': 'Asked by this barangay only'})
    a.submit()
    a.expect_saved('Saved as proposed')
    a.go('brgy.requirements', status='proposed', q=name)
    a.assert_healthy('proposed requirements')
    r = row_with(a, name)
    t.contains(r.text, 'Proposed', 'waits for an approver')
    t.contains(r.text, 'only ' + t.state['entity'], 'for its entity only')

    a.as_user('manager')
    a.go('brgy.requirements', status='proposed', q=name)
    a.click_and_wait(row_with(a, name).find_element(By.CSS_SELECTOR, 'form button'))
    a.expect_saved('Approved')
    a.go('brgy.requirements', q=name)
    r = row_with(a, name)
    t.contains(r.text, 'Approved', 'approved')
    a.click_and_wait(r.find_element(By.CSS_SELECTOR, 'a'))
    a.assert_healthy('edit requirement')
    a.fill_form({'name': name + ' (current)', 'requirement_type': 'Conditional'})
    a.submit()
    a.expect_saved('Saved')
    a.go('brgy.requirements', q=name)
    r = row_with(a, name + ' (current)')
    t.contains(r.text, 'Conditional', 'the changed kind')
    t.contains(r.text, 'Approved', 'an approver\'s change stays approved')
    t.contains(r.text, code, 'its code')


@S.case('Procurement modes: add a mode of our own and edit it')
def modes(t):
    a = t.app
    name = fx.unique('Selenium Negotiated Mode')
    code = 'sel_' + fx.code('m', 14).lower()
    a.as_user('manager')
    a.go('brgy.modes')
    a.assert_healthy('procurement modes')
    form = a.form_with('legal_basis')
    a.fill_form({'name': name, 'code': code, 'regime': 'RA 12009', 'legal_basis': 'IRR Sec. 53 (Selenium)', 'default_retention_pct': '5',
                 'needs_perf_security': True}, root=form)
    a.submit(root=form)
    a.expect_saved('Mode added')
    r = row_with(a, name)
    t.contains(r.text, code, 'its code')
    t.contains(r.text, 'performance', 'its security')
    t.contains(r.text, '5.00%', 'its retention')
    # the same code again is refused
    form = a.form_with('legal_basis')
    a.fill_form({'name': name + ' again', 'code': code}, root=form)
    a.submit(root=form)
    a.expect_refused('already used')
    a.click_and_wait(row_with(a, name).find_element(By.CSS_SELECTOR, 'a'))
    a.assert_healthy('edit mode')
    form = a.form_with('legal_basis')
    a.fill_form({'max_abc': '250000', 'is_active': False, 'notes': 'Ceiling verified (Selenium)'}, root=form)
    a.submit(root=form)
    a.expect_saved('Saved')
    r = row_with(a, name)
    t.contains(r.text, '250,000.00', 'the ceiling')
    t.check('text-muted' in (r.get_attribute('class') or ''), 'shown as inactive')


@S.case('Regulatory references: add one of our own, edit it and mark it checked')
def references(t):
    a = t.app
    code = 'SEL-' + fx.code('R', 14)
    title = fx.unique('Selenium circular on barangay purchases')
    a.as_user('manager')
    a.go('brgy.references')
    a.assert_healthy('references')
    form = a.form_with('applies_to')
    a.fill_form({'code': code, 'title': title, 'ref_type': 'Circular', 'issuer': 'GPPB', 'issued_date': fx.today(-30),
                 'effective_date': fx.today(-15), 'url': 'https://www.gppb.gov.ph/', 'applies_to': 'Small purchases (Selenium)'}, root=form)
    a.submit(root=form)
    a.expect_saved('Reference added')
    r = row_with(a, code)
    t.contains(r.text, title, 'its title')
    t.contains(r.text, 'never', 'not checked yet')
    a.click_and_wait(r.find_element(By.CSS_SELECTOR, f'a[href*="edit="]'))
    a.assert_healthy('edit reference')
    form = a.form_with('applies_to')
    a.fill_form({'title': title + ' (amended)', 'notes': 'Amended (Selenium)'}, root=form)
    a.submit(root=form)
    a.expect_saved('Saved')
    r = row_with(a, code)
    t.contains(r.text, title + ' (amended)', 'the edited title')
    a.click_and_wait(r.find_element(By.CSS_SELECTOR, 'form button'))
    a.expect_saved('checked today')
    t.contains(row_with(a, code).text, mdy(fx.today()), 'checked today')


@S.case('Contracting Settings save without changes')
def settings(t):
    a = t.app
    a.as_user('manager')
    a.go('brgy.settings')
    a.assert_healthy('contracting settings')
    before = {e.get_attribute('name'): e.is_selected() for e in a.finds('input[type=checkbox][role=switch]')}
    a.submit()
    a.expect_saved('Nothing changed')
    a.assert_healthy('settings saved')
    after = {e.get_attribute('name'): e.is_selected() for e in a.finds('input[type=checkbox][role=switch]')}
    t.equal(after, before, 'the controls as they were')


# ------------------------------------------------------------------------------------------- reports
def brgy_reports():
    from core.pages import PAGES
    return [k for k in PAGES if k.startswith('rpt.brgy_')]


@S.case('Every report of the module opens and shows rows; the contract is in the main ones', needs=['entity_id'])
def reports(t):
    a = t.app
    a.as_user('manager')
    eid = t.state['entity_id']
    pno = t.state.get('pno')
    ours = {'rpt.brgy_bid_register': {}, 'rpt.brgy_contract_status': {'scope': 'all'}, 'rpt.brgy_inspection': {},
            'rpt.brgy_billing_pack': {}, 'rpt.brgy_wht_credits': {}, 'rpt.brgy_retention': {}, 'rpt.brgy_profitability': {},
            'rpt.brgy_submissions': {}}
    for key in brgy_reports():
        def one(key=key):
            extra = ours.get(key, {})
            a.go(key, dates='all', entity_id=eid, **extra)
            a.assert_healthy(key)
            rows = report_rows(a)
            if key in ours and pno and t.state.get('closed'):
                t.check(any(pno in r.text for r in rows), f'{pno} in the report')
            if not rows:
                a.go(key, dates='all')
                a.assert_healthy(key)
                rows = report_rows(a)
            t.check(rows, 'the report shows rows')
            if key == 'rpt.brgy_reconciliation' and pno and t.state.get('closed'):
                t.contains(row_with(a, pno, '#rptTable tbody tr').text, 'Agrees', 'the contract agrees with the ledger')
        t.sub(key, one)


# ------------------------------------------------------------------------------------------- Office files in the vault
_OFFICE = os.path.join(os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))), 'fixtures', 'office')


def office_copy(name):
    """A copy of a sample file of tests/fixtures/office with bytes of its own (the vault refuses a file it already holds):
    a zipped file (docx, xlsx, pptx) gets one more part; a binary one (doc, xls, ppt) a few bytes at its end, which
    Word, Excel and PowerPoint ignore."""
    import shutil
    import zipfile as zf
    src = os.path.join(_OFFICE, name)
    if not os.path.isfile(src):
        raise CheckFailed('The sample file is missing: tests/fixtures/office/' + name)
    base, ext = os.path.splitext(name)
    dst = os.path.join(fx._dir(), f'{base}_{fx.stamp()}{ext}')
    shutil.copyfile(src, dst)
    mark = fx.unique('Selenium')
    if ext in ('.docx', '.xlsx', '.pptx'):
        with zf.ZipFile(dst, 'a') as z:
            z.writestr('docProps/selenium.txt', mark)
    else:
        with open(dst, 'ab') as f:
            f.write(b'\0' * 7 + mark.encode('ascii', 'replace'))
    return dst


@S.case('Document Vault: Word, Excel and PowerPoint files (old and new versions) show on the page in the quick view')
def office_preview(t):
    a = t.app
    a.as_user('bidofficer')
    cases = [('supplier_profile.docx', 'Word document', ['Supplier Profile Test', 'Total (merged)', 'Second bullet'], '.op-page'),
             ('supplier_profile_97.doc', 'Word 97-2003', ['Supplier Profile Test', 'Flashlight', 'Step one'], '.op-page'),
             ('price_schedule_97.xls', 'Excel 97-2003', ['Tanod uniform', '171,030.00', '367,830.00'], '.op-grid'),
             ('price_schedule.xlsx', 'Excel workbook', ['Tanod uniform', '2,850.50'], '.op-grid'),
             ('bid_presentation.pptx', 'PowerPoint presentation', ['Bid Presentation', 'Supply and delivery'], '.op-slide'),
             ('bid_presentation_97.ppt', 'PowerPoint 97-2003', ['Bid Presentation', 'Within thirty days'], '.op-slide')]
    for name, label, words, part in cases:
        def one(name=name, label=label, words=words, part=part):
            a.go('brgy.vault', tab='all')
            a.assert_healthy('vault, all documents')
            form = a.find('form[action*="a=upload"]')
            title = 'Office preview ' + name + ' ' + fx.stamp()
            a.fill_form({'file': office_copy(name), 'doc_type': 'Other document', 'title': title}, root=form)
            a.submit(root=form)
            msg = a.expect_saved('Filed')
            doc = int(re.search(r'#(\d+)', msg).group(1))
            a.go('brgy.vault', a='view', id=doc)
            a.assert_healthy('vault document ' + name)
            t.contains(a.find('.op-card .op-sub').text, label, 'the kind of file is named')
            quick = a.find('[data-op-mode=quick]')
            if 'active' not in (quick.get_attribute('class') or ''):
                a.click(quick)
            a.wait_for(lambda: a.exists('[data-op-stage] ' + part), f'the quick view of {name}', timeout=30)
            stage = a.find('[data-op-stage]').text
            for w in words:
                t.contains(stage, w, f'{name}: the quick view')
            t.check(not a.exists('[data-op-stage] script'), 'nothing active in the quick view')
            if part == '.op-grid':
                # the second sheet opens from its tab
                tabs = a.finds('[data-op-sheet]')
                t.check(len(tabs) == 2, 'two sheets, two tabs')
                a.click(tabs[1])
                a.wait_for(lambda: a.find('[data-op-panel="1"]').is_displayed(), 'the second sheet shows')
                t.check(not a.find('[data-op-panel="0"]').is_displayed(), 'the first sheet is hidden')
            page = a.find('[data-op-mode=page]')
            t.check(page.get_attribute('disabled') is not None or page.is_enabled(), 'the page view button is there (on with LibreOffice on the server)')
            a.assert_healthy('vault document ' + name + ' after the quick view')
        t.sub(name, one)
