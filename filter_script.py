import re

# --- FILTER MILESTONE.md ---
with open('MILESTONE.md', 'r') as f:
    content = f.read()

# Sections to remove entirely (using regex between #### and the next #### or ---)
def remove_section(text, header_pattern):
    return re.sub(r'#### ' + header_pattern + r'.*?(?=#### |---)', '', text, flags=re.DOTALL)

content = remove_section(content, r'C3\. Finance & Profitability')
content = remove_section(content, r'C8\. Cash Position')
content = remove_section(content, r'C9\. Tax Management')
content = remove_section(content, r'C10\. Warranty System')
content = remove_section(content, r'C11\. AI & Super AI')
content = remove_section(content, r'D2\. Cash Bank')
content = remove_section(content, r'D3\. Journal & Accounting')
content = remove_section(content, r'D4\. Invoicing')
content = remove_section(content, r'D6\. Sales Settlements')
content = remove_section(content, r'D8\. Taxes')

# Renumber C sections
c_map = {'C4': 'C3', 'C5': 'C4', 'C6': 'C5', 'C7': 'C6', 'C12': 'C7'}
for old, new in c_map.items():
    content = content.replace(f'#### {old}.', f'#### {new}.')

# Renumber D sections
d_map = {'D5': 'D2', 'D7': 'D3', 'D9': 'D4'}
for old, new in d_map.items():
    content = content.replace(f'#### {old}.', f'#### {new}.')

# Remove from Milestone descriptions
content = re.sub(r'- \[ \] FinanceService:.*?\n', '', content)
content = re.sub(r'- \[ \] CashService:.*?\n', '', content)
content = re.sub(r'- \[ \] WarrantyService:.*?\n', '', content)
content = re.sub(r'- \[ \] AI Chat foundation.*?\n', '', content)
content = re.sub(r'- \[ \] Tax management.*?\n', '', content)
content = re.sub(r'- \[ \] Journal, Invoicing, Cash Bank\n', '', content)
content = re.sub(r'- \[ \] Settlements tracking\n', '', content)

# Remove from Progress Tracker
content = re.sub(r'\| \*\*Finance/Profitability\*\*.*?\n', '', content)
content = re.sub(r'\| \*\*Cash/Tax\*\*.*?\n', '', content)
content = re.sub(r'\| \*\*Warranty\*\*.*?\n', '', content)
content = re.sub(r'\| \*\*AI/SuperAI\*\*.*?\n', '', content)
content = re.sub(r'\| \*\*Accounting \(Journal/Invoice/CashBank\)\*\*.*?\n', '', content)

# Remove from Architecture Notes
content = re.sub(r'   - Finance profitability \(CTE-based SQL, per-SKU fee allocation\)\n', '', content)

with open('MILESTONE.md', 'w') as f:
    f.write(content)
