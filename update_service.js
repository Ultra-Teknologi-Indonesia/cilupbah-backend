const fs = require('fs');
const file = 'Modules/Warehouse/app/Services/LocationBinService.php';
let code = fs.readFileSync(file, 'utf-8');

// Replace Location::find with locationRepository->find
code = code.replace(/Location::find\(\$locationId\)/g, '$this->locationRepository->find($locationId)');
// Replace LocationBin::find with binRepository->findById
code = code.replace(/LocationBin::find\(\$binId\)/g, '$this->binRepository->findById($binId)');

fs.writeFileSync(file, code);
