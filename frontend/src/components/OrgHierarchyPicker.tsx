'use client';

import { useState, useEffect } from 'react';
import { ChevronDown, ChevronRight, Search } from 'lucide-react';

interface OrgUnit {
  id: number;
  name: string;
  short_code: string;
  parent_id: number | null;
  org_unit_type_id: number;
  children: OrgUnit[];
}

interface OrgHierarchyPickerProps {
  onSelect: (orgUnit: OrgUnit) => void;
  selectedId?: number;
  placeholder?: string;
}

export default function OrgHierarchyPicker({ onSelect, selectedId, placeholder = 'Select local church...' }: OrgHierarchyPickerProps) {
  const [hierarchy, setHierarchy] = useState<OrgUnit[]>([]);
  const [expandedUnits, setExpandedUnits] = useState<Set<number>>(new Set());
  const [searchQuery, setSearchQuery] = useState('');
  const [searchResults, setSearchResults] = useState<OrgUnit[]>([]);
  const [isOpen, setIsOpen] = useState(false);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    fetchHierarchy();
  }, []);

  const fetchHierarchy = async () => {
    setLoading(true);
    try {
      const response = await fetch('/api/v1/org-hierarchy');
      const data = await response.json();
      setHierarchy(data);
    } catch (error) {
      console.error('Failed to fetch hierarchy:', error);
    } finally {
      setLoading(false);
    }
  };

  const handleSearch = async (query: string) => {
    setSearchQuery(query);
    if (query.length < 2) {
      setSearchResults([]);
      return;
    }

    try {
      const response = await fetch(`/api/v1/org-units/search?q=${encodeURIComponent(query)}`);
      const data = await response.json();
      setSearchResults(data.results);
    } catch (error) {
      console.error('Search failed:', error);
    }
  };

  const toggleExpand = (unitId: number) => {
    setExpandedUnits(prev => {
      const next = new Set(prev);
      if (next.has(unitId)) {
        next.delete(unitId);
      } else {
        next.add(unitId);
      }
      return next;
    });
  };

  const handleSelect = (unit: OrgUnit) => {
    onSelect(unit);
    setIsOpen(false);
    setSearchQuery('');
    setSearchResults([]);
  };

  const renderUnit = (unit: OrgUnit, level: number = 0) => {
    const isExpanded = expandedUnits.has(unit.id);
    const isSelected = unit.id === selectedId;
    const hasChildren = unit.children && unit.children.length > 0;

    return (
      <div key={unit.id} className="select-none">
        <div
          className={`flex items-center gap-2 py-2 px-3 rounded-lg cursor-pointer transition-colors ${
            isSelected ? 'bg-brand-600 text-white' : 'hover:bg-slate-700 text-slate-300'
          }`}
          style={{ paddingLeft: `${12 + level * 16}px` }}
          onClick={() => {
            handleSelect(unit);
            if (hasChildren) toggleExpand(unit.id);
          }}
        >
          {hasChildren && (
            <button
              onClick={(e) => {
                e.stopPropagation();
                toggleExpand(unit.id);
              }}
              className="p-1 hover:bg-slate-600 rounded"
            >
              {isExpanded ? <ChevronDown className="w-4 h-4" /> : <ChevronRight className="w-4 h-4" />}
            </button>
          )}
          <span className="text-sm">{unit.name}</span>
          {unit.short_code && <span className="text-xs opacity-60">({unit.short_code})</span>}
        </div>
        {isExpanded && hasChildren && (
          <div className="mt-1">
            {unit.children.map(child => renderUnit(child, level + 1))}
          </div>
        )}
      </div>
    );
  };

  return (
    <div className="relative">
      <button
        onClick={() => setIsOpen(!isOpen)}
        className="w-full input flex items-center justify-between"
      >
        <span className="text-slate-400">
          {selectedId ? 'Selected church' : placeholder}
        </span>
        <ChevronDown className="w-4 h-4 text-slate-500" />
      </button>

      {isOpen && (
        <div className="absolute z-50 w-full mt-2 bg-slate-800 border border-slate-700 rounded-lg shadow-xl max-h-96 overflow-hidden">
          <div className="p-3 border-b border-slate-700">
            <div className="relative">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-500" />
              <input
                type="text"
                placeholder="Search churches..."
                value={searchQuery}
                onChange={(e) => handleSearch(e.target.value)}
                className="w-full pl-10 pr-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-sm text-slate-200 placeholder-slate-500 focus:outline-none focus:border-brand-500"
              />
            </div>
          </div>

          <div className="p-2 overflow-y-auto max-h-72">
            {loading ? (
              <div className="text-center py-8 text-slate-500 text-sm">Loading...</div>
            ) : searchQuery.length >= 2 ? (
              searchResults.length > 0 ? (
                searchResults.map(unit => (
                  <div
                    key={unit.id}
                    className="py-2 px-3 rounded-lg cursor-pointer hover:bg-slate-700 text-slate-300 text-sm"
                    onClick={() => handleSelect(unit as OrgUnit)}
                  >
                    {unit.name} <span className="opacity-60">({unit.short_code})</span>
                  </div>
                ))
              ) : (
                <div className="text-center py-8 text-slate-500 text-sm">No results found</div>
              )
            ) : hierarchy.length > 0 ? (
              hierarchy.map(unit => renderUnit(unit))
            ) : (
              <div className="text-center py-8 text-slate-500 text-sm">No hierarchy available</div>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
