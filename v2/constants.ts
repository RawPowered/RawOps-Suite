// Fix: Removed file content markers that were causing syntax errors.
import type { Product, Job, Stat, Category, SellerFeature } from './types';

export const NAV_LINKS = [
  { name: 'Shop', href: '#' },
  { name: 'Lifestyle', href: '#' },
  { name: 'Our Standards', href: '#vetting' },
];

export const FEATURED_PRODUCTS: Product[] = [
  { 
    id: 1, 
    name: 'Product "Momentum" Oversized Hoodie', 
    category: 'Performance Apparel', 
    imageUrl: 'https://images.unsplash.com/photo-1620799140408-edc6d5f9650d?q=80&w=800&auto=format&fit=crop',
    description: 'Engineered for comfort and style, this premium heavyweight cotton hoodie is perfect for both workouts and rest days.',
    price: 79.99,
  },
  { 
    id: 2, 
    name: 'Pure-Form Micronized Creatine', 
    category: 'Sports Nutrition', 
    imageUrl: 'https://images.unsplash.com/photo-1605030753481-1354C13442c4?q=80&w=800&auto=format&fit=crop',
    description: '5g of pure micronized creatine monohydrate per serving to boost strength, power, and muscle growth. No fillers.',
    price: 29.99,
  },
  { 
    id: 3, 
    name: 'Cogni-Boost Nootropic Formula', 
    category: 'Focus Supplements', 
    imageUrl: 'https://images.unsplash.com/photo-1630950523196-2d371e54e196?q=80&w=800&auto=format&fit=crop',
    description: 'Enhance mental clarity, focus, and cognitive function with our advanced blend of brain-boosting ingredients.',
    price: 44.99,
  },
  { 
    id: 5, 
    name: 'ISO-Peak Whey Protein Isolate', 
    category: 'Sports Nutrition', 
    imageUrl: 'https://images.unsplash.com/photo-1579758629938-03607ccdbaba?q=80&w=800&auto=format&fit=crop',
    description: '25g of fast-absorbing whey protein isolate per scoop to support muscle recovery and growth. Delicious and easy to mix.',
    price: 54.99,
  },
];


export const PRODUCTS: Product[] = [
  { 
    id: 1, 
    name: 'Product "Momentum" Oversized Hoodie', 
    category: 'Performance Apparel', 
    imageUrl: 'https://images.unsplash.com/photo-1620799140408-edc6d5f9650d?q=80&w=800&auto=format&fit=crop',
    description: 'Engineered for comfort and style, this premium heavyweight cotton hoodie is perfect for both workouts and rest days.',
    price: 79.99,
  },
  { 
    id: 2, 
    name: 'Pure-Form Micronized Creatine', 
    category: 'Sports Nutrition', 
    imageUrl: 'https://images.unsplash.com/photo-1605030753481-1354C13442c4?q=80&w=800&auto=format&fit=crop',
    description: '5g of pure micronized creatine monohydrate per serving to boost strength, power, and muscle growth. No fillers.',
    price: 29.99,
  },
  { 
    id: 3, 
    name: 'Cogni-Boost Nootropic Formula', 
    category: 'Focus Supplements', 
    imageUrl: 'https://images.unsplash.com/photo-1630950523196-2d371e54e196?q=80&w=800&auto=format&fit=crop',
    description: 'Enhance mental clarity, focus, and cognitive function with our advanced blend of brain-boosting ingredients.',
    price: 44.99,
  },
  { 
    id: 4, 
    name: 'Urban-Flow Tech Track Pants', 
    category: 'Lifestyle Fashion', 
    imageUrl: 'https://images.unsplash.com/photo-1548123378-bde499316935?q=80&w=800&auto=format&fit=crop',
    description: 'Sleek, comfortable, and versatile. These tech-fabric track pants are designed for the modern, active lifestyle.',
    price: 89.99,
  },
  { 
    id: 5, 
    name: 'ISO-Peak Whey Protein Isolate', 
    category: 'Sports Nutrition', 
    imageUrl: 'https://images.unsplash.com/photo-1579758629938-03607ccdbaba?q=80&w=800&auto=format&fit=crop',
    description: '25g of fast-absorbing whey protein isolate per scoop to support muscle recovery and growth. Delicious and easy to mix.',
    price: 54.99,
  },
  { 
    id: 6, 
    name: 'Pro-Grade Resistance Band Set', 
    category: 'Fitness Accessories', 
    imageUrl: 'https://images.unsplash.com/photo-1599583812235-91043b257523?q=80&w=800&auto=format&fit=crop',
    description: 'A set of five durable, high-resistance latex bands to intensify your workouts and target specific muscle groups.',
    price: 34.99,
  },
];

export const TOP_SELLERS: Product[] = [
  {
    id: 4, 
    name: 'Urban-Flow Tech Track Pants', 
    category: 'Lifestyle Fashion', 
    imageUrl: 'https://images.unsplash.com/photo-1552319208-c1cf3673e334?q=80&w=800&auto=format&fit=crop',
    description: 'Sleek, comfortable, and versatile. These tech-fabric track pants are designed for the modern, active lifestyle.',
    price: 89.99,
  },
  {
    id: 6, 
    name: 'Pro-Grade Resistance Band Set', 
    category: 'Fitness Accessories', 
    imageUrl: 'https://images.unsplash.com/photo-1590487988256-5ed24a3e3633?q=80&w=800&auto=format&fit=crop',
    description: 'A set of five durable, high-resistance latex bands to intensify your workouts and target specific muscle groups.',
    price: 34.99,
  },
  {
    id: 1, 
    name: 'Product "Momentum" Oversized Hoodie', 
    category: 'Performance Apparel', 
    imageUrl: 'https://images.unsplash.com/photo-1515886657613-9f3515b0c78f?q=80&w=800&auto=format&fit=crop',
    description: 'Engineered for comfort and style, this premium heavyweight cotton hoodie is perfect for both workouts and rest days.',
    price: 79.99,
  }
];

export const CATEGORIES: Category[] = [
  { name: 'Performance Apparel', description: 'Gear engineered for your peak performance and comfort.', icon: 'fa-shirt', href: '#' },
  { name: 'Sports Nutrition', description: 'Fuel your body with premium supplements for maximum results.', icon: 'fa-blender-phone', href: '#' },
  { name: 'Focus & Nootropics', description: 'Enhance cognitive function and unlock your mental potential.', icon: 'fa-brain', href: '#' },
  { name: 'Fitness Accessories', description: 'The essential tools you need to elevate every workout.', icon: 'fa-dumbbell', href: '#' },
];

export const MEGA_MENU_COLLECTIONS = [
  { name: 'The Performance Stack', href: '#', description: 'Bundles for peak performance.' },
  { name: 'Modern Herbs+', href: '#', description: 'Advanced herbal supplements.' },
  { name: 'Product Apparel', href: '#', description: 'Gear engineered for your peak.' },
];

export const MEGA_MENU_FEATURED_PRODUCT: Product = {
  id: 5, 
  name: 'ISO-Peak Whey Protein Isolate', 
  category: 'Sports Nutrition', 
  imageUrl: 'https://images.unsplash.com/photo-1579758629938-03607ccdbaba?q=80&w=800&auto=format&fit=crop',
  description: '25g of fast-absorbing whey protein isolate per scoop.',
  price: 54.99,
};


export const SELLER_FEATURES: SellerFeature[] = [
  {
    title: 'Unified Affiliate System',
    description: 'Manage all your brand partnerships and campaigns from a single, powerful dashboard. One system, endless opportunities.',
    icon: 'fa-sitemap'
  },
  {
    title: 'Reach Your Target Audience',
    description: 'Connect directly with thousands of highly engaged wellness seekers and performance-focused individuals ready to buy.',
    icon: 'fa-bullseye'
  },
  {
    title: 'Streamlined Brand Growth',
    description: 'We handle the logistics, you focus on creating amazing products. Leverage our platform for effortless e-commerce management.',
    icon: 'fa-rocket'
  }
];

export const JOBS: Job[] = [
  { id: 1, title: 'Remote Sales Agent', type: 'Commission-Based', description: 'Drive sales for top-tier wellness brands on the Product platform.' },
  { id: 2, title: 'Platform Store Manager', type: 'Full-Time', description: 'Manage and optimize e-commerce stores for our vendor partners.' },
  { id: 3, title: 'Product Photography Specialist', type: 'Freelance', description: 'Create stunning, high-quality product images that meet our strict standards.' },
  { id: 4, title: 'Affiliate Program Manager', type: 'Contract', description: 'Develop and manage affiliate strategies for a portfolio of wellness brands.' },
];

export const HIRING_STATS: Stat[] = [
  { value: '$85k+', label: 'Avg. Top Earner Income', icon: 'fa-solid fa-sack-dollar' },
  { value: '78%', label: 'Successful Placement Rate', icon: 'fa-solid fa-user-check' },
  { value: '1,200+', label: 'Active Professionals', icon: 'fa-solid fa-users' },
];
