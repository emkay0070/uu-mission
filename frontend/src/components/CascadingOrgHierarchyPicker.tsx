'use client';

import { useState, useEffect } from 'react';
import { refApi } from '@/lib/api';

interface OrgUnit {
  id: number;
  name: string;
  short_code: string;
  parent_id: number | null;
  org_unit_type_id: number;
}

interface CascadingOrgHierarchyPickerProps {
  onSelect: (orgUnit: OrgUnit) => void;
  selectedId?: number | null;
}

type SelectionLevel = 'union' | 'conference' | 'zone' | 'district' | 'local_church' | 'complete';

export default function CascadingOrgHierarchyPicker({ onSelect, selectedId }: CascadingOrgHierarchyPickerProps) {
  const [orgUnits, setOrgUnits] = useState<OrgUnit[]>([]);
  const [loading, setLoading] = useState(false);
  
  // Current selection level
  const [currentLevel, setCurrentLevel] = useState<SelectionLevel>('union');
  
  // Selected values at each level
  const [union, setUnion] = useState<OrgUnit | null>(null);
  const [conference, setConference] = useState<OrgUnit | null>(null);
  const [zone, setZone] = useState<OrgUnit | null>(null);
  const [district, setDistrict] = useState<OrgUnit | null>(null);
  const [localChurch, setLocalChurch] = useState<OrgUnit | null>(null);

  useEffect(() => {
    fetchOrgUnits();
  }, []);

  const fetchOrgUnits = async () => {
    setLoading(true);
    try {
      const response = await fetch('/api/v1/org-hierarchy', {
        headers: {
          'Authorization': `Bearer ${localStorage.getItem('uu_token')}`,
          'Accept': 'application/json',
        },
      });
      const data = await response.json();
      console.log('Org hierarchy response:', data);
      
      // Flatten the hierarchical structure into a flat array
      const flattenOrgUnits = (units: any[]): OrgUnit[] => {
        const result: OrgUnit[] = [];
        const traverse = (items: any[]) => {
          items.forEach((item: any) => {
            result.push({
              id: item.id,
              name: item.name,
              short_code: item.short_code,
              parent_id: item.parent_id,
              org_unit_type_id: item.org_unit_type_id,
            });
            if (item.children && item.children.length > 0) {
              traverse(item.children);
            }
          });
        };
        traverse(units);
        return result;
      };
      
      const flattened = flattenOrgUnits(Array.isArray(data) ? data : []);
      console.log('Flattened org units:', flattened);
      setOrgUnits(flattened);
    } catch (error) {
      console.error('Failed to fetch org units:', error);
      setOrgUnits([]);
    } finally {
      setLoading(false);
    }
  };

  // Get children of a specific org unit
  const getChildren = (parentId: number | null): OrgUnit[] => {
    return orgUnits.filter(unit => unit.parent_id === parentId);
  };

  // Handle selection at each level
  const handleSelect = (orgUnit: OrgUnit) => {
    switch (currentLevel) {
      case 'union':
        setUnion(orgUnit);
        setCurrentLevel('conference');
        break;
      case 'conference':
        setConference(orgUnit);
        setCurrentLevel('zone');
        break;
      case 'zone':
        setZone(orgUnit);
        setCurrentLevel('district');
        break;
      case 'district':
        setDistrict(orgUnit);
        setCurrentLevel('local_church');
        break;
      case 'local_church':
        setLocalChurch(orgUnit);
        setCurrentLevel('complete');
        onSelect(orgUnit);
        break;
    }
  };

  // Handle going back to previous level
  const handleBack = () => {
    switch (currentLevel) {
      case 'conference':
        setUnion(null);
        setCurrentLevel('union');
        break;
      case 'zone':
        setConference(null);
        setCurrentLevel('conference');
        break;
      case 'district':
        setZone(null);
        setCurrentLevel('zone');
        break;
      case 'local_church':
        setDistrict(null);
        setCurrentLevel('district');
        break;
    }
  };

  // Get options for current level
  const getCurrentOptions = (): OrgUnit[] => {
    switch (currentLevel) {
      case 'union':
        return getChildren(null);
      case 'conference':
        return union ? getChildren(union.id) : [];
      case 'zone':
        return conference ? getChildren(conference.id) : [];
      case 'district':
        return zone ? getChildren(zone.id) : [];
      case 'local_church':
        return district ? getChildren(district.id) : [];
      default:
        return [];
    }
  };

  // Get label for current level
  const getCurrentLabel = (): string => {
    switch (currentLevel) {
      case 'union':
        return 'Select Union';
      case 'conference':
        return 'Select Conference / Field';
      case 'zone':
        return 'Select Zone';
      case 'district':
        return 'Select District';
      case 'local_church':
        return 'Select Local Church';
      default:
        return '';
    }
  };

  // Get selection path for display
  const getSelectionPath = (): string => {
    const parts = [];
    if (union) parts.push(union.name);
    if (conference) parts.push(conference.name);
    if (zone) parts.push(zone.name);
    if (district) parts.push(district.name);
    if (localChurch) parts.push(localChurch.name);
    return parts.join(' → ');
  };

  const options = getCurrentOptions();

  if (loading) {
    return (
      <div className="text-center py-8">
        <div className="inline-block w-6 h-6 border-2 border-brand-500/30 border-t-brand-500 rounded-full animate-spin" />
        <p className="text-slate-400 text-sm mt-3">Loading...</p>
      </div>
    );
  }

  if (currentLevel === 'complete') {
    return (
      <div className="text-center py-8">
        <div className="w-12 h-12 rounded-full bg-green-500/20 border border-green-500/30 flex items-center justify-center mx-auto mb-3">
          <svg className="w-6 h-6 text-green-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
          </svg>
        </div>
        <p className="text-slate-200 font-medium mb-1">{localChurch?.name}</p>
        <p className="text-slate-500 text-sm">{getSelectionPath()}</p>
      </div>
    );
  }

  return (
    <div className="space-y-4">
      {/* Selection path breadcrumb */}
      {(union || conference || zone || district) && (
        <div className="flex items-center gap-2 text-xs text-slate-500 mb-4">
          <span className="truncate">{getSelectionPath()}</span>
          {currentLevel !== 'union' && (
            <button
              onClick={handleBack}
              className="text-brand-400 hover:text-brand-300 shrink-0"
            >
              ← Back
            </button>
          )}
        </div>
      )}

      {/* Current level label */}
      <h3 className="text-sm font-semibold text-slate-300">{getCurrentLabel()}</h3>

      {/* Options grid */}
      {options.length === 0 ? (
        <p className="text-slate-500 text-sm">No options available</p>
      ) : (
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          {options.map((option) => (
            <button
              key={option.id}
              onClick={() => handleSelect(option)}
              className="text-left p-4 bg-white/5 border border-white/8 rounded-2xl hover:bg-white/10 hover:border-brand-500/50 transition-all group"
            >
              <p className="text-slate-200 font-medium text-sm group-hover:text-brand-300 transition-colors">
                {option.name}
              </p>
              {option.short_code && (
                <p className="text-slate-500 text-xs mt-1">{option.short_code}</p>
              )}
            </button>
          ))}
        </div>
      )}
    </div>
  );
}
