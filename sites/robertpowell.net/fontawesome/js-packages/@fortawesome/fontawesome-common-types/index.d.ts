export type IconPrefix = "fas" | "fab" | "far" | "fal" | "fad";
export type IconPathData = string | string[]

export interface IconLookup {
  prefix: IconPrefix;
  // IconName is defined in the code that will be generated at build time and bundled with this file.
  iconName: IconName;
}

export interface IconDefinition extends IconLookup {
  icon: [
    number, // width
    number, // height
    string[], // ligatures
    string, // unicode
    IconPathData // svgPathData
  ];
}

export interface IconPack {
  [key: string]: IconDefinition;
}

export type IconName = 'exclamation-triangle' | 
  'calendar-alt' | 
  'car' | 
  'check' | 
  'clock' | 
  'door-open' | 
  'envelope' | 
  'envelope-open' | 
  'home' | 
  'lock-alt' | 
  'map-marker-alt' | 
  'minus' | 
  'moon' | 
  'notes-medical' | 
  'parking' | 
  'phone' | 
  'plus' | 
  'plus-circle' | 
  'question-circle' | 
  'sun' | 
  'times' | 
  'user' | 
  'users' | 
  'check-square' | 
  'phone';
