import React, { useState, useEffect, useMemo, useRef } from 'react';
import { Users, Shuffle, Download, Info, UserPlus, Grid3X3, Edit3, CheckCircle2, FileUp, FileDown } from 'lucide-react';

export default function App() {
  const [studentInput, setStudentInput] = useState("Ola\nKari\nPer\nPål\nEspen\nAskeladden\nNora\nEmma\nJakob\nEmil\nSofie\nLinnea\nLukas\nFilip\nHenrik\nOskar\nAmalie\nMia\nIngrid\nMathias");
  const [students, setStudents] = useState([]);
  
  // Rutenett og design (Starter nå med 5 rader og 6 kolonner)
  const [gridRows, setGridRows] = useState(5);
  const [gridCols, setGridCols] = useState(6);
  const [activeDesks, setActiveDesks] = useState(new Set()); 
  const [isDesignMode, setIsDesignMode] = useState(false);
  
  const printRef = useRef(null); 
  const fileInputRef = useRef(null); // Referanse for opplasting av CSV
  
  const [assignments, setAssignments] = useState({}); 
  const [selectedEntity, setSelectedEntity] = useState(null); 

  // Sett opp standard pulter første gang (Alle 5x6 plasser = 30 pulter er nå aktive)
  useEffect(() => {
    const list = studentInput.split('\n').map(s => s.trim()).filter(s => s.length > 0);
    setStudents(list);
    
    const initialDesks = new Set();
    for (let r = 0; r < 5; r++) {
      for (let c = 0; c < 6; c++) {
        // Legger til alle pultene i rutenettet uavhengig av antall elever
        initialDesks.add(`${r}-${c}`);
      }
    }
    setActiveDesks(initialDesks);
  }, []); // Kjøres kun ved oppstart

  // Last inn html2pdf for lagring av PDF
  useEffect(() => {
    const script = document.createElement('script');
    script.src = "https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js";
    script.async = true;
    document.body.appendChild(script);
    
    return () => {
      if (document.body.contains(script)) {
        document.body.removeChild(script);
      }
    };
  }, []);

  // Generer elever fra tekstfelt
  const handleUpdateStudents = () => {
    const list = studentInput
      .split('\n')
      .map(s => s.trim())
      .filter(s => s.length > 0);
    setStudents(list);
  };

  // Håndter endring av rutenettdimensjoner
  const handleGridChange = (type, value) => {
    const num = Math.max(2, Math.min(15, parseInt(value) || 2));
    const newRows = type === 'rows' ? num : gridRows;
    const newCols = type === 'cols' ? num : gridCols;

    if (type === 'rows') setGridRows(num);
    if (type === 'cols') setGridCols(num);

    // Hvis rutenettet krymper, fjern pulter og elever som havner utenfor
    if (newRows < gridRows || newCols < gridCols) {
      setActiveDesks(prev => {
        const next = new Set();
        prev.forEach(id => {
          const [r, c] = id.split('-').map(Number);
          if (r < newRows && c < newCols) {
            next.add(id);
          }
        });
        return next;
      });

      setAssignments(prev => {
        const next = { ...prev };
        for (const deskId in next) {
          const [r, c] = deskId.split('-').map(Number);
          if (r >= newRows || c >= newCols) {
            delete next[deskId];
          }
        }
        return next;
      });
    }
  };

  // Toggle pult av/på i design-modus
  const toggleDesk = (id) => {
    setActiveDesks(prev => {
      const next = new Set(prev);
      if (next.has(id)) {
        next.delete(id);
        setAssignments(prevAss => {
          const newAss = { ...prevAss };
          delete newAss[id];
          return newAss;
        });
      } else {
        next.add(id);
      }
      return next;
    });
  };

  // Tilfeldig plassering (Shuffle)
  const handleShuffle = () => {
    if (students.length === 0) return;
    
    const shuffled = [...students].sort(() => Math.random() - 0.5);
    const newAssignments = {};
    const deskArray = Array.from(activeDesks);
    
    for (let i = 0; i < Math.min(shuffled.length, deskArray.length); i++) {
      newAssignments[deskArray[i]] = shuffled[i];
    }
    
    setAssignments(newAssignments);
    setSelectedEntity(null);
  };

  // Finn ut hvem som ikke har fått plass
  const unassignedStudents = useMemo(() => {
    const assignedNames = Object.values(assignments);
    return students.filter(student => !assignedNames.includes(student));
  }, [students, assignments]);

  // Logikk for å bytte plass (Klikk-og-bytt i plasseringsmodus)
  const handleEntityClick = (type, id, studentName) => {
    if (isDesignMode) return; 

    if (!selectedEntity) {
      setSelectedEntity({ type, id, name: studentName });
      return;
    }

    if (selectedEntity.id === id) {
      setSelectedEntity(null);
      return;
    }

    const nextAssignments = { ...assignments };

    if (selectedEntity.type === 'desk' && type === 'desk') {
      const temp = nextAssignments[id];
      if (selectedEntity.name) nextAssignments[id] = selectedEntity.name;
      else delete nextAssignments[id];
      
      if (temp) nextAssignments[selectedEntity.id] = temp;
      else delete nextAssignments[selectedEntity.id];
    } 
    else if (selectedEntity.type === 'unassigned' && type === 'desk') {
      nextAssignments[id] = selectedEntity.name;
    }
    else if (selectedEntity.type === 'desk' && type === 'unassigned') {
       nextAssignments[selectedEntity.id] = studentName;
    }

    setAssignments(nextAssignments);
    setSelectedEntity(null);
  };

  const handleSavePDF = () => {
    if (!window.html2pdf) return;

    const currentSelected = selectedEntity;
    setSelectedEntity(null);

    const element = printRef.current;
    const opt = {
      margin:       5,
      filename:     'klassekart.pdf',
      image:        { type: 'jpeg', quality: 0.98 },
      html2canvas:  { scale: 2, useCORS: true },
      jsPDF:        { unit: 'mm', format: 'a4', orientation: 'landscape' }
    };

    window.html2pdf().set(opt).from(element).save().then(() => {
      setSelectedEntity(currentSelected); 
    });
  };

  // --- NYTT: CSV Eksport ---
  const handleExportCSV = () => {
    // Vi bygger opp en strukturert CSV for å kunne gjenopprette oppsettet nøyaktig.
    // Vi legger til en UTF-8 BOM (\uFEFF) slik at Excel leser norske tegn (æ, ø, å) riktig.
    let csvContent = "\uFEFFType,Verdi1,Verdi2,Verdi3\n";
    
    // Lagre grid-størrelse
    csvContent += `Grid,${gridRows},${gridCols},\n`;

    // Lagre alle aktive pulter og hvem som sitter der
    activeDesks.forEach(deskId => {
      const [r, c] = deskId.split('-');
      // Fjerner eventuelle kommaer i navn for å ikke ødelegge CSV-strukturen
      const student = (assignments[deskId] || "").replace(/,/g, ''); 
      csvContent += `Desk,${r},${c},${student}\n`;
    });

    // Lagre hele elevlisten
    students.forEach(student => {
      const safeName = student.replace(/,/g, '');
      csvContent += `Student,${safeName},,\n`;
    });

    // Trig nedlasting
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.setAttribute("href", url);
    link.setAttribute("download", "klassekart_data.csv");
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
  };

  // --- NYTT: CSV Import ---
  const handleImportCSV = (event) => {
    const file = event.target.files[0];
    if (!file) return;

    const reader = new FileReader();
    reader.onload = (e) => {
      const text = e.target.result;
      const lines = text.split('\n');

      let newRows = 5;
      let newCols = 6;
      const newDesks = new Set();
      const newAssignments = {};
      const newStudents = [];

      lines.forEach(line => {
        // Fjerner BOM og whitespace
        const cleanLine = line.replace(/^\uFEFF/, '').trim();
        if (!cleanLine) return;

        const parts = cleanLine.split(',');
        const type = parts[0];

        if (type === 'Grid' && parts.length >= 3) {
          newRows = parseInt(parts[1], 10) || 5;
          newCols = parseInt(parts[2], 10) || 6;
        } else if (type === 'Desk' && parts.length >= 3) {
          const r = parts[1];
          const c = parts[2];
          const student = parts[3] || "";
          const deskId = `${r}-${c}`;
          newDesks.add(deskId);
          if (student) {
            newAssignments[deskId] = student;
          }
        } else if (type === 'Student' && parts.length >= 2) {
          const studentName = parts[1].trim();
          if (studentName) newStudents.push(studentName);
        }
      });

      // Oppdater state med importert data
      setGridRows(newRows);
      setGridCols(newCols);
      setActiveDesks(newDesks);
      setAssignments(newAssignments);
      setStudents(newStudents);
      setStudentInput(newStudents.join('\n'));
    };
    
    reader.readAsText(file);
    // Nullstill input slik at samme fil kan velges på nytt om ønskelig
    event.target.value = null; 
  };

  return (
    <div className="flex h-screen bg-gray-100 font-sans text-gray-800">
      
      {/* Skjult fil-input for CSV opplasting */}
      <input 
        type="file" 
        accept=".csv" 
        ref={fileInputRef} 
        style={{ display: 'none' }} 
        onChange={handleImportCSV} 
      />

      {/* SIDEBAR */}
      <div className="w-80 bg-white border-r shadow-lg flex flex-col no-print z-10 relative shrink-0">
        <div className="p-6 bg-blue-600 text-white flex items-center justify-between">
          <div className="flex items-center gap-3">
              <Users size={24} />
              <h1 className="text-xl font-bold">Klassekart</h1>
            </div>
          </div>

          <div className="flex-1 overflow-y-auto p-6 space-y-6">
            
            {/* Elevliste */}
            <div className="space-y-2">
              <label className="block text-sm font-semibold text-gray-700">
                1. Legg inn elever
              </label>
              <textarea
                className="w-full h-24 p-3 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none"
                value={studentInput}
                onChange={(e) => setStudentInput(e.target.value)}
                placeholder="Ola Nordmann&#10;Kari Nilsen"
              />
              <button
                onClick={handleUpdateStudents}
                className="w-full bg-gray-800 text-white py-2 rounded-md hover:bg-gray-700 transition font-medium text-sm"
              >
                Oppdater liste ({students.length} elever)
              </button>
            </div>

            {/* Rutenett og Oppsett */}
            <div className="space-y-3 pt-4 border-t">
              <div className="flex items-center justify-between">
                <label className="text-sm font-semibold text-gray-700 flex items-center gap-2">
                  <Grid3X3 size={16} />
                  2. Design rutenett
                </label>
              </div>
              
              <div className="flex gap-4">
                <div className="flex-1">
                  <span className="text-xs text-gray-500 block mb-1">Rader</span>
                  <input 
                    type="number" min="2" max="15" 
                    value={gridRows} onChange={(e) => handleGridChange('rows', e.target.value)}
                    className="w-full border rounded p-1 text-center text-sm"
                  />
                </div>
                <div className="flex-1">
                  <span className="text-xs text-gray-500 block mb-1">Kolonner</span>
                  <input 
                    type="number" min="2" max="15" 
                    value={gridCols} onChange={(e) => handleGridChange('cols', e.target.value)}
                    className="w-full border rounded p-1 text-center text-sm"
                  />
                </div>
              </div>

              {/* Teller og feilmelding hvis ubalanse */}
              <div className={`p-2 rounded text-sm text-center font-medium border ${
                activeDesks.size >= students.length 
                  ? 'bg-green-50 text-green-700 border-green-200' 
                  : 'bg-yellow-50 text-yellow-700 border-yellow-200'
              }`}>
                {activeDesks.size} aktive pulter / {students.length} elever
              </div>

              <button
                onClick={() => setIsDesignMode(!isDesignMode)}
                className={`w-full py-2 rounded-md transition font-medium flex items-center justify-center gap-2 text-sm border-2 ${
                  isDesignMode 
                    ? 'bg-green-600 border-green-600 text-white shadow-inner' 
                    : 'bg-white border-blue-600 text-blue-600 hover:bg-blue-50'
                }`}
              >
                {isDesignMode ? <CheckCircle2 size={16} /> : <Edit3 size={16} />}
                {isDesignMode ? 'Ferdig med å bygge' : 'Bygg rutenett (Dra/klikk)'}
              </button>
              {isDesignMode && (
                 <p className="text-xs text-gray-500 text-center">Klikk på rutene i kartet for å legge til eller fjerne pulter.</p>
              )}
            </div>

            {/* Handlinger */}
          <div className="space-y-3 pt-4 border-t">
            <button
              onClick={handleShuffle}
              disabled={isDesignMode}
              className="w-full bg-blue-600 disabled:bg-gray-400 text-white py-3 rounded-md hover:bg-blue-700 transition font-medium flex items-center justify-center gap-2 shadow-sm"
            >
              <Shuffle size={18} />
              Plasser tilfeldig
            </button>

            <button
              onClick={handleSavePDF}
              disabled={isDesignMode}
              className="w-full bg-white disabled:bg-gray-100 disabled:text-gray-400 border border-gray-300 text-gray-700 py-2 rounded-md hover:bg-gray-50 transition font-medium flex items-center justify-center gap-2 text-sm"
            >
              <Download size={16} />
              Lagre visning (PDF)
            </button>

            <div className="flex gap-2 w-full pt-2">
               <button
                onClick={handleExportCSV}
                disabled={isDesignMode}
                title="Lagre oppsett og elever som fil"
                className="flex-1 bg-white disabled:bg-gray-100 disabled:text-gray-400 border border-gray-300 text-gray-700 py-2 rounded-md hover:bg-gray-50 transition font-medium flex items-center justify-center gap-2 text-xs"
              >
                <FileDown size={14} />
                Eksportér CSV
              </button>
              <button
                onClick={() => fileInputRef.current.click()}
                disabled={isDesignMode}
                title="Hent oppsett og elever fra fil"
                className="flex-1 bg-white disabled:bg-gray-100 disabled:text-gray-400 border border-gray-300 text-gray-700 py-2 rounded-md hover:bg-gray-50 transition font-medium flex items-center justify-center gap-2 text-xs"
              >
                <FileUp size={14} />
                Importér CSV
              </button>
            </div>
          </div>

          {/* Uplasserte elever */}
            {!isDesignMode && unassignedStudents.length > 0 && (
              <div className="pt-4 border-t">
                <label className="text-sm font-semibold text-red-600 flex items-center gap-2 mb-2">
                  <UserPlus size={16} />
                  Uplasserte elever ({unassignedStudents.length})
                </label>
                <div className="flex flex-wrap gap-2 max-h-40 overflow-y-auto p-2 bg-red-50 rounded-md border border-red-100">
                  {unassignedStudents.map(name => {
                    const isSelected = selectedEntity?.type === 'unassigned' && selectedEntity?.name === name;
                    return (
                      <div
                        key={name}
                        onClick={() => handleEntityClick('unassigned', `unassigned-${name}`, name)}
                        className={`px-3 py-1 text-sm rounded-full cursor-pointer transition-colors shadow-sm ${
                          isSelected 
                            ? 'bg-yellow-400 text-yellow-900 ring-2 ring-yellow-500 font-bold' 
                            : 'bg-white border border-gray-300 hover:bg-gray-100'
                        }`}
                      >
                        {name}
                      </div>
                    );
                  })}
                </div>
              </div>
            )}

            {/* Tipsboks */}
          {!isDesignMode && (
            <div className="bg-blue-50 text-blue-800 p-3 rounded-md text-xs flex gap-2">
              <Info size={16} className="shrink-0 mt-0.5" />
              <p>For å bytte plass, klikk på en elev, og deretter på pulten de skal flytte til.</p>
            </div>
          )}
        </div>
      </div>

      {/* KLASSEROM (HOVEDVISNING) */}
      <div className="flex-1 p-4 md:p-8 overflow-auto flex bg-gray-200">
        <div 
          ref={printRef} 
          style={{ aspectRatio: '1.414', minHeight: '500px' }}
          className="print-area m-auto w-full max-w-5xl bg-white relative flex flex-col rounded-xl shadow-xl border-4 border-gray-100 overflow-hidden shrink-0"
        >
          
          {/* Elevpulter (Rutenett) */}
          <div className="flex-1 w-full h-full p-4 sm:p-8 pb-36 sm:pb-40">
            <div 
              className="w-full h-full grid gap-2 sm:gap-3 lg:gap-4"
              style={{
                gridTemplateColumns: `repeat(${gridCols}, minmax(0, 1fr))`,
                gridTemplateRows: `repeat(${gridRows}, minmax(0, 1fr))`
              }}
            >
              {Array.from({ length: gridRows }).map((_, r) => (
                Array.from({ length: gridCols }).map((_, c) => {
                  const id = `${r}-${c}`;
                  const isActive = activeDesks.has(id);
                  const studentName = assignments[id];
                  const isSelected = selectedEntity?.id === id;
                  const isEmpty = !studentName;

                  if (isDesignMode) {
                    // Visning under DESIGN-MODUS
                    return (
                      <div
                        key={id}
                        onClick={() => toggleDesk(id)}
                        className={`w-full h-full min-h-[2rem] flex items-center justify-center rounded-md cursor-pointer transition-all ${
                          isActive 
                            ? 'bg-blue-100 border-2 border-blue-500 shadow-sm' 
                            : 'bg-gray-50 border-2 border-dashed border-gray-300 hover:bg-gray-100'
                        }`}
                      >
                        {isActive && <div className="w-2 h-2 rounded-full bg-blue-500"></div>}
                      </div>
                    );
                  }

                  // Visning under VANLIG MODUS (Plassering)
                  if (!isActive) {
                    // Tomme felt i gridet som fungerer som mellomrom
                    return <div key={id} className="w-full h-full min-h-[2rem]"></div>;
                  }

                  return (
                    <div
                      key={id}
                      onClick={() => handleEntityClick('desk', id, studentName)}
                      className={`relative w-full h-full min-h-[2.5rem] flex flex-col items-center justify-center rounded-md cursor-pointer transition-all duration-200 
                        ${isSelected ? 'ring-4 ring-yellow-400 z-10 scale-105 shadow-xl' : 'hover:scale-105 hover:shadow-md'}
                        ${isEmpty ? 'border-2 border-dashed border-gray-300 bg-gray-50' : 'border border-gray-200 shadow-sm'}
                      `}
                      style={{
                        backgroundColor: isEmpty ? undefined : '#f0fdf4',
                        borderColor: isEmpty ? undefined : '#bbf7d0'
                      }}
                    >
                      {/* Navn inni pulten */}
                      <span className={`text-[10px] sm:text-xs md:text-sm font-semibold text-center w-full px-1 truncate ${isEmpty ? 'text-gray-400' : 'text-green-900'}`}>
                        {studentName || '+'}
                      </span>
                      
                      {/* Visualisering av stol */}
                      <div className={`absolute -bottom-2 sm:-bottom-3 w-4 sm:w-6 h-2 sm:h-3 rounded-b-full ${isEmpty ? 'bg-gray-200' : 'bg-green-300'}`}></div>
                    </div>
                  );
                })
              ))}
            </div>
          </div>

          {/* Lærerens tavle/pult */}
          <div className="absolute bottom-4 sm:bottom-6 left-1/2 -translate-x-1/2 w-1/3 h-10 sm:h-12 bg-gray-800 rounded-t-lg shadow-md flex items-center justify-center text-white font-bold text-sm sm:text-lg tracking-wider opacity-90">
            TAVLE
          </div>
          <div className="absolute bottom-16 sm:bottom-24 left-1/2 -translate-x-1/2 w-24 sm:w-32 h-8 sm:h-10 border-2 border-gray-300 bg-gray-50 rounded shadow-sm flex items-center justify-center text-gray-500 text-xs sm:text-sm font-medium">
            Lærerpult
          </div>
          
        </div>
      </div>
    </div>
  );
}