// Fix: Removed constants and self-import. This file should only contain type definitions.
export interface Product {
  id: number;
  name: string;
  category: string;
  imageUrl: string;
  description: string;
  price: number;
}

export interface Job {
  id: number;
  title: string;
  type: string;
  description: string;
}

export interface Stat {
  value: string;
  label: string;
  icon: string;
}

export interface Category {
  name: string;
  description: string;
  icon: string;
  href: string;
}

export interface SellerFeature {
  title: string;
  description: string;
  icon: string;
}